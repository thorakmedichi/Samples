<?php

class Cost {

	const DATE = 'date';
	const CAMPAIGN = 'campaign_name';
	const ADGROUP = 'adgroup_name';
	const COST = 'cost';
	const QS = 'qs';
	const CLICKS = 'clicks';
	const IMPRESSIONS = 'impressions';
	const VALIDATION_ERROR_CODE = 6;
	const MAX_ROW_COUNT = 10000;
	const ALLOCATION_OFFSET = 80;

	/**
	 * Download the file from S3
	 * Validate the file and its contents
	 * Normalize the file data and organize
	 * Save the contents in the DB
	 * @param  int 		$id 	alchemy_cost.input id
	 * @return nothing
	 */
	public static function process($id, $accountType, $dateFormat = null) {
		$user = User::getCurrent();

		$file = CostInput::getCostInput($id, $user);
		if (!$file) {
			return false;
		}


		// Get the file from S3
		try{
			$tempFile = CostFile::downloadFromS3($file->s3_key);
		}
		catch (Exception $ex){
			Log::error($ex);
			throw new Exception ('There was an error processing your file. Please try again or contact the administrator if this problem persists.' . (($user && $user->hasAccess('admin')) ? '<br/>Error: ' . getFormattedExceptionMessage($ex): ''));
		}


		// Get the list of column names based on the account type
		try {
			$params =  Cost::getAccountParams($file->account_type);
		}
		catch (Exception $ex){
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error processing your file. Please try again or contact the administrator if this problem persists.' . (($user && $user->hasAccess('admin')) ? '<br/>Error: ' . getFormattedExceptionMessage($ex): ''));
		}


		// Validate file type and size
		try {
			CostFile::validateFile($tempFile);
		}
		catch (Exception $ex){
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error validating your file. <br/>' . $ex->getMessage(), self::VALIDATION_ERROR_CODE);
		}


		// Get the file contents and normalize into required format to save
		try {
			$structuredArray = Cost::buildArrayFromCSV($tempFile);
			$filteredArray = Cost::filterArray($structuredArray, $params);
		}
		catch (Exception $ex){
			if ($ex->getCode() === self::VALIDATION_ERROR_CODE) {
				Log::error($ex);
				self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
				throw new Exception('File exceeds the max row count of 10,000. Please reduce the row count and try again.');
			}
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error processing your file. Please try again or contact the administrator if this problem persists.' . ($user && $user->hasAccess('admin') ? '<br/>Error: ' . getFormattedExceptionMessage($ex): ''));
		}


		// Validate the file contents
		try {
			Cost::validateFileColumns($filteredArray);
		}
		catch (Exception $ex){
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error validating your file. <br/>' . $ex->getMessage(), self::VALIDATION_ERROR_CODE);
		}

		// Figure out the date format in the file
		if (!$dateFormat || empty($dateFormat)){
			try {
				$dateFormat = self::determineDateFormat($filteredArray);
			}
			catch (Exception $ex){
				Log::error($ex);
				self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
				throw new Exception ('There was an error determining the date format of your file.');
			}
		}

		// Validate the Date field for every row based on the determined date format.
		// We want to do this just to make sure the date format doesnt change part way
		// through the file for some bizzar reason
		if ($dateFormat) {
			$valid = true;
			foreach ($filteredArray as $row){
				if (!Functions::isValidDate($row[self::DATE], $dateFormat)){
					self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
					throw new Exception ('There was an error validating your file. <br/>The date format you selected does not match the file.', self::VALIDATION_ERROR_CODE);
				}
			}
		}

		// Update CostFile DB with the new date format
		try {
			CostFile::updateDateFormat($file->file_id, $dateFormat);
		}
		catch (Exception $ex){
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error saving your data. Please contact the administrator if the problem persists.');
		}

		// Group data together
		try {
			$groupedArray = Cost::groupArray($filteredArray); // total all the rows
			$groupedArray = self::convertDates($groupedArray, $dateFormat, $file->account_timezone);
		}
		catch (Exception $ex) {
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error saving your data. Please try again or contact the administrator if this problem persists.' . ($user && $user->hasAccess('admin') ? '<br/>Error: ' . getFormattedExceptionMessage($ex): ''));
		}

		// Check for any unmapped names
		$unmappedNames = Cost::getUnmappedNames($file->account_id, $groupedArray);
		if (!empty($unmappedNames)) {
			// Session::Flash can only handle up to 1445 characters so we need to limit large result sets
			$messageUnmapped = array_slice($unmappedNames, 0, 30);
			if (count($unmappedNames) > 30){
				$messageUnmapped[] = 'And several others';
			}
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception('The following "Campaign Name - Adgroup Name" combinations are unmapped. Please map them and re-submit: ' . HTML::ul($messageUnmapped), self::VALIDATION_ERROR_CODE);
		}

		// Save the data
		try {
			CostRaw::storeData($file->id, $file->account_id, $groupedArray);
			CostSummary::storeData($file->account_id, $groupedArray);
		}
		catch (Exception $ex){
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error saving your data. Please try again or contact the administrator if this problem persists.' . ($user && $user->hasAccess('admin') ? '<br/>Error: ' . getFormattedExceptionMessage($ex): ''));
		}


		// Update the alchemy_cost.input table
		try {
			$dates = Cost::getMinMaxTimes($groupedArray);
			CostInput::updateStartEndDates($file->id, $dates['min'], $dates['max']);
			CostInput::updateStatus($file->id, CostInput::STATUS_SUCCESS);
		}
		catch (Exception $ex){
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error saving your data. Please try again or contact the administrator if this problem persists.' . ($user && $user->hasAccess('admin') ? '<br/>Error: ' . getFormattedExceptionMessage($ex): ''));
		}


		// Add to cost allocation queue
		try {
			Cost::addDatesToAllocationQueue($dates['dates']);
		}
		catch (Exception $ex){
			Log::error($ex);
			self::errorCleanup($id, $file->type, $file->file_id, $file->s3_key, $tempFile);
			throw new Exception ('There was an error saving your data. Please try again or contact the administrator if this problem persists.' . ($user && $user->hasAccess('admin') ? '<br/>Error: ' . getFormattedExceptionMessage($ex): ''));
		}


		// Delete temp file that was downloaded
		try {
        	CostFile::deleteFileAndPath($tempFile);
        }
        catch (Exception $ex){
			Log::error($ex);
		}
	}

	public static function errorCleanup($inputId, $type, $fileId, $key, $tempFile){

		CostFile::deleteFileAndPath($tempFile);

		if ($type === 'upload'){
			CostFile::deleteFromS3($key);
			CostFile::deleteCostFile($fileId);
			CostInput::deleteCostInput($inputId);
		}

	}

	public static function addDatesToAllocationQueue($dates) {
		if (empty($dates)) {
			return false;
		}

		foreach ($dates as $date) {
			DB::insert('REPLACE INTO allocation_queue_cost VALUES (?)', array($date));
		}

		return true;
	}


	/**
	 * Get the Start and End dates from our normalized array
	 * @param  array	$data 	The normalized ($groupedArray)
	 * @return array       		Start and End date from file
	 */
	public static function getMinMaxTimes($data){
		$dates = array();
		foreach($data as $row){
			$dates[] = $row[self::DATE];
		}

		$dates = array_unique($dates);

		$minMax['dates'] = $dates;
		$minMax['min'] = min($dates);
		$minMax['max'] = max($dates);

		return $minMax;
	}

	/**
	 * Validate the file contents to ensure it has the correct columns we need
	 * @param  string	$filename	The $filteredArray we created from the actual file
	 * @return bool   				true or thow and exception
	 */
	public static function validateFileColumns($data){
		$missingCols = array();

		// Check that all required columns exist
		$required = self::getRequiredCols();
		foreach ($required as $col){
			if (!array_key_exists($col, end($data))){
				$missingCols[] = ucfirst($col);
			}
		}
		if (!empty($missingCols)){
			throw new Exception ('The file is missing the following required columns: '. implode(', ', $missingCols));
		}
		return true;
	}

	/**
	 * This function will correct dates that are incorrectly set
	 * For example strtotime will fail if the date is DD/MM/YY
	 * instead of the accepted format of MM/DD/YY
	 * @param  array 	$groupedArray 	The final array in the CSV conversion process
	 * @param  string 	$dateFormat 	The date format that is expected in unix conversion
	 * @param  string 	$timezone 		The timezone of the account processing the file
	 * @return array               		The same array as the param but with corrected date in unixtime
	 */
	public static function convertDates($groupedArray, $dateFormat, $timezone){
		if (!empty($timezone)){
			date_default_timezone_set($timezone);
		}

		foreach ($groupedArray as $outerKey => $row){
			foreach($row as $key => $val){
				if ($key === self::DATE){

					if ($dateFormat){
						$groupedArray[$outerKey][self::DATE] = DateTime::createFromFormat('!'.$dateFormat, $val)->getTimestamp();
					}
					else {
						$groupedArray[$outerKey][self::DATE] = strtotime($val);
					}
				}
			}
		}

		return $groupedArray;
	}

	public static function determineDateFormat($filteredArray){
		$date1 = array();
		$date2 = array();
		$date3 = array();

		foreach($filteredArray as $row){
			$date = $row['date'];
			// Split the date into the three date parts based on non numeric delimiters (\D)
			preg_match("/([0-9]+)(\D)([0-9]+)\D([0-9]+)/", $date, $dateParts);

			$delimiter = $dateParts[2];
			$date1[] = $dateParts[1];
			$date2[] = $dateParts[3];
			$date3[] = $dateParts[4];
		}

		// Lets see if we can determine a month or year right away without doing heavy lifting
		$dateTypes['date1'] = self::monthOrYearQuickCheck($date1);
		$dateTypes['date2'] = self::monthOrYearQuickCheck($date2);
		$dateTypes['date3'] = self::monthOrYearQuickCheck($date3);

		// If we have the month and year from that first check the last should be day
		if (self::determineMissingDateType($dateTypes)){
			return self::assembleDateFormat($dateTypes, array('date1'=>$date1, 'date2'=>$date2, 'date3'=>$date3), $delimiter);
		}

		// If or quick tests failed lets check the frequency of any missing values
		$freqs['date1'] = self::frequencyCheck($date1);
		$freqs['date2'] = self::frequencyCheck($date2);
		$freqs['date3'] = self::frequencyCheck($date3);

		// Get the key name(s) of the highest frequency date parts
		$maxs = array_keys($freqs, max(array_unique($freqs)));

		// If there is only one max then this must be the day
		if (count($maxs) === 1){
			$dateTypes[$maxs[0]] = 'day';
		}

		// Loop through the three date parts and try and solve any remaining date types
		foreach ($dateTypes as $key => $value){
			// If the date part is not already solved try and figure it out
			if (empty($value)){
				// If the highest value of this $date# is greater than 12 AND
				// the difference is more than 1 it must be a day because no file
				// will span more than two years
				if ((max(${$key}) > 12) && (max(${$key}) - min(${$key}) > 1)) {
					$dateTypes[$key] = 'day';
				}
				// If it wasnt a day then check if it is a year by relaxing the requirements
				else if (max(${$key}) > 12){
					$dateTypes[$key] = 'year';
				}
			}
		}

		// By now we should have at least two values so the third can be determined
		if (self::determineMissingDateType($dateTypes)){
			return self::assembleDateFormat($dateTypes, array('date1'=>$date1, 'date2'=>$date2, 'date3'=>$date3), $delimiter);
		}

		return false;
	}

	public static function assembleDateFormat($dateTypes, $dates, $delimiter){
		$format = '';
		foreach ($dateTypes as $key => $value){

			if ($value == end($dateTypes)){
				$delim = '';
			} else {
				$delim = $delimiter;
			}

			if ($value == 'year'){
				$year = 'y';
				foreach ($dates[$key] as $val){
					if (strlen($val) === 4){
						$year = 'Y';
						break;
					}
				}
				$format .= $year . $delim;
			}
			else if ($value == 'month'){
				$month = 'm';
				foreach ($dates[$key] as $val){
					if (strlen($val) == 1){
						$month = 'n';
						break;
					}
				}
				$format .= $month . $delim;
			}
			else if ($value == 'day'){
				$day = 'd';
				foreach ($dates[$key] as $val){
					if (strlen($val) == 1){
						$day = 'j';
						break;
					}
				}
				$format .= $day . $delim;
			}
		}

		return $format;
	}

	public static function determineMissingDateType(&$dateTypes){
		$validTypes = array('day', 'month', 'year');

		// Remove any duplicates from the initial quick tests
		$uniqueVals = array_unique($dateTypes);
		// If we got lucky and have all the values already just return true
		if (count(array_filter($uniqueVals)) === 3){
			return true;
		}
		// If there are 2 of the 3 values in the list the last must be the day
		if (count(array_filter($uniqueVals)) === 2){
			// Determine what is missing from the array
			$missingType = array_diff($validTypes, $dateTypes);
			$missingType = reset($missingType);

			// Determine where it is missing from and add it
			foreach ($dateTypes as $key => $value){
				if (empty($value)){
					$dateTypes[$key] = $missingType;
				}
			}
			return true;
		}

		return false;
	}

	public static function frequencyCheck($dates){
		$freq = array_unique($dates);
		return count($freq);
	}

	public static function monthOrYearQuickCheck($dates){
		// First check to see if it is 4 digits. If it is it must be a year
		if (strlen($dates[0]) === 4){
			return 'year';
		}

		// A file MUST have two days of data so if the difference is 0
		// then it must be a month
		if (((max($dates) - min($dates)) === 0) && (end($dates) <= 12)){
			return 'month';
		}
	}

	/**
	 * Get an array of required columns in order to begin processing file
	 * @return bool   				Array list of required columns
	 */
	public static function getRequiredCols(){
		return array(self::DATE, self::CAMPAIGN, self::ADGROUP, self::COST);
	}

	/**
	 * Get a list of column name conversions that need to happen
	 * This returned array will be used to normalize the data into a structure of
	 * column names that we need for the DB insertions
	 * @param  string	$type		The $filteredArray we created from the actual file
	 * @return bool   				true or thow and exception
	 */
	public static function getAccountParams($type) {
		$params = array(
			'bing' => array(
				self::DATE 		=> 'Gregorian date',
				self::CAMPAIGN 	=> 'Campaign name',
				self::ADGROUP 	=> 'Ad group',
				self::COST 		=> 'Spend',
				self::QS 		=> 'Quality score',
				self::CLICKS 	=> 'Clicks',
				self::IMPRESSIONS => 'Impressions'
			),
			'adwords' => array(
				self::DATE 		=> 'Day',
				self::CAMPAIGN 	=> 'Campaign',
				self::ADGROUP 	=> 'Ad group',
				self::COST 		=> 'Cost',
				self::QS 		=> 'Quality score',
				self::CLICKS 	=> 'Clicks',
				self::IMPRESSIONS => 'Impressions'
			),
		);

		return $params[$type];
	}

	public static function getUnmappedNames($accountId, $data) {
		$uniqueNames = array();
		foreach ($data as $row) {
			$key = $row['campaign_name'] . ' - ' . $row['adgroup_name'];
			if (isset($uniqueNames[$key])) continue;
			$uniqueNames[$key] = true;
		}

		$unmapped = array();
		foreach ($uniqueNames as $name => $value) {
			list($campaignName, $adgroupName) = explode(' - ', $name);
			$mappedAdgroup = Cost::getMappedAdgroup($accountId, $campaignName, $adgroupName);
			if (empty($mappedAdgroup)) {
				$unmapped[] = $name;
			}
		}

		return $unmapped;
	}

	public static function getMappedAdgroup($accountId, $campaignName, $adgroupName) {
		$query = '	SELECT 	ad.*
					FROM 	adgroup ad
						JOIN account_story acs ON ad.account_story_id = acs.id
					WHERE 	acs.account_id = ?
						AND ad.campaign_name = ?
						AND ad.adgroup_name = ?

					UNION

					SELECT 	ad2.*
					FROM 	adgroup_name adn
						JOIN adgroup ad2 ON adn.adgroup_id = ad2.id
						JOIN account_story acs2 ON ad2.account_story_id = acs2.id
					WHERE 	acs2.account_id = ?
						AND adn.campaign_name = ?
						AND adn.adgroup_name = ?';

		$result = DB::select($query, array(
			$accountId,
			$campaignName,
			$adgroupName,
			$accountId,
			$campaignName,
			$adgroupName,
		));

		return !empty($result) ? $result[0] : false;
	}

	/**
	 * This function will take an associative array and reduce its number of
	 * elements by grouping data by date, campaign name and adgroup as well as
	 * summing all of the clicks, costs, impressions and weighted quality score
	 * @param  array 	$data     	Our normalized $filteredArray
	 * @param  string	$timezone 	The timezone of the account this data is for
	 * @return array           		The final array that can be used to insert data into the DB
	 *
	 * Array (
	 *	    [2015-05-01|Kids Games|Kids Games] => Array
	 *	        (
	 *	            [date] => 1430438400
	 *	            [campaign_name] => Kids Games
	 *	            [adgroup_name] => Kids Games
	 *	            [cost] => 1.85
	 *	            [qs] => 6
	 *	            [clicks] => 39
	 *	            [impressions] => 347
	 *	        )
	 *
	 *	    [2015-05-02|Math Games|Math Games] => Array
	 *	        (
	 *	            [date] => 1430524800
	 *	            [campaign_name] => Math Games
	 *	            [adgroup_name] => Math Games
	 *	            [cost] => 24.5
	 *	            [qs] => 6
	 *	            [clicks] => 477
	 *	            [impressions] => 4977
	 *	        )
	 */
	public static function groupArray($data){
		$newArr = array();
		$weightedQS = array();

		foreach ($data as $row => $array){ // Loop through rows

			// If the date column doesnt have a numeric value then skip it
			if (!is_numeric(substr($data[$row][self::DATE], 0, 1))){
				continue;
			}

			$uniqueKey = $data[$row][self::DATE].'|'.$data[$row][self::CAMPAIGN].'|'.$data[$row][self::ADGROUP];

			/*
			 * BUILD ARRAY
			 */
			// If the unique key doesnt exist create it and add initial values
			if (empty($newArr[$uniqueKey])){
				$newArr[$uniqueKey][self::DATE] = $data[$row][self::DATE];
				$newArr[$uniqueKey][self::CAMPAIGN] = $data[$row][self::CAMPAIGN];
				$newArr[$uniqueKey][self::ADGROUP] = $data[$row][self::ADGROUP];
				$newArr[$uniqueKey][self::COST] = (double)$data[$row][self::COST];
				$newArr[$uniqueKey][self::QS] = !empty($data[$row][self::QS]) ? (int)$data[$row][self::QS] : 0;
				$newArr[$uniqueKey][self::CLICKS] = !empty($data[$row][self::CLICKS]) ? (int)$data[$row][self::CLICKS] : null;
				$newArr[$uniqueKey][self::IMPRESSIONS] = !empty($data[$row][self::IMPRESSIONS]) ? (int)$data[$row][self::IMPRESSIONS] : null;

				// Set initial values for the weighted quality score vars
				$weightedQS[$uniqueKey]['qs'] = 0;
				$weightedQS[$uniqueKey]['clicks'] = 0;
			}
			// If the unique key does exists then add the new values to the old
			else {
				$newArr[$uniqueKey][self::COST] += $data[$row][self::COST];
				$newArr[$uniqueKey][self::CLICKS] += $data[$row][self::CLICKS];
				$newArr[$uniqueKey][self::IMPRESSIONS] += $data[$row][self::IMPRESSIONS];
			}


			/*
			 * DETERMINE WEIGHTED QS
			 */
			$existsClicks = isset($newArr[$uniqueKey][self::CLICKS]);
			$existsImpressions = isset($newArr[$uniqueKey][self::IMPRESSIONS]);
			$existsQS = isset($newArr[$uniqueKey][self::QS]);

			if ($existsClicks && $existsQS){
				// If the value of clicks is 0 then make it 1 for weighting purposes
				$clicks = ($newArr[$uniqueKey][self::CLICKS] > 0) ? $newArr[$uniqueKey][self::CLICKS] : 1;
				$weightedQS[$uniqueKey]['qs'] += $clicks * $newArr[$uniqueKey][self::QS];
				$weightedQS[$uniqueKey]['clicks'] += $clicks;
			}
			else if ($existsImpressions && $existsQS){
				// If the value of impressions is 0 then make it 1 for weighting purposes
				$impressions = ($newArr[$uniqueKey][self::IMPRESSIONS] > 0) ? $newArr[$uniqueKey][self::IMPRESSIONS] : 1;
				$weightedQS[$uniqueKey]['qs'] += $impressions * $newArr[$uniqueKey][self::QS];
				$weightedQS[$uniqueKey]['clicks'] += $impressions;
			}
		}

		// Add weighted QS back into the main array
		if (($existsClicks && $existsQS) || ($existsImpressions && $existsQS)){
			foreach ($newArr as $key => $val) {
				$newArr[$key][self::QS] =  $weightedQS[$key]['qs'] && $weightedQS[$key]['clicks'] ? $weightedQS[$key]['qs'] / $weightedQS[$key]['clicks'] : null;
			}
		}

		return $newArr;
	}

	/**
	 * This function will process our raw array of data from the CSV
	 * It will remove columns (elements) that are not requested
	 * It will also change the named keys to the ones we need to match the DB
	 * @param  array 	$data   	Our associative array $structuredArray
	 * @param  array 	$filter 	Array with list of column name replacements
	 * @return array         		Array with reduced columns with proper names
	 *
	 * Array(
	 *	    [1] => Array
	 *	        (
	 *	            [date] => 2015-05-01
	 *	            [campaign_name] => Kids Games
	 *	            [adgroup_name] => Kids Games
	 *	            [clicks] => 1
	 *	            [impressions] => 5
	 *	            [cost] => 0.04
	 *	            [qs] => 6
	 *	        )
 	 *
	 *	    [2] => Array
	 *	        (
	 * 	            [date] => 2015-05-02
	 *	            [campaign_name] => Math Games
	 *	            [adgroup_name] => Math Games
	 *	            [clicks] => 1
	 *	            [impressions] => 5
	 *	            [cost] => 0.05
	 *	            [qs] => 6
	 *	        )
	 *	)
	 */
	public static function filterArray($data, $filter){
		$newArr = array();

		foreach ($data as $row => $array){ // Loop through rows
			foreach ($array as $headerTitle => $value){ // Loop through cols
				// If it is not one of the columns we need remove it
				if (!in_array($headerTitle, $filter)){
					unset($data[$row][$headerTitle]);
				}
				// If it is a column we want set the key to the DB field
				else {
					$dbField = array_search($headerTitle, $filter);
					$newArr[$row][$dbField] = $value;
				}
			}
		}

		return $newArr;
	}

	/**
	 * Takes our various CSV or Excel files and builds a multidimensional
	 * associative array we can start to process.
	 * It starts by checking for the first row that contains the column names
	 * Builds a list of the column names to be used as named keys for the array
	 * The values for each cell are added to the named keys
	 * @param  string 	$filename 	The filename and path to the file to be parsed
	 * @return array          		See Sample below
	 *
	 * Array(
	 *	    [1] => Array
	 *	        (
	 *	            [Day] => 2015-05-01
	 *	            [Keyword state] => enabled
	 *	            [Keyword] => free kids games nickelodeon
	 *	            [Campaign] => Kids Games
	 *	            [Ad group] => Kids Games
	 *	            [Status] => below first page bid (First page cpc : 0.11)
	 *	            [Max. CPC] => 0.05
	 *	            [Clicks] => 1
	 *	            [Impressions] => 5
	 *	            [CTR] => 20.00%
	 *	            [Avg. CPC] => 0.04
	 *	            [Cost] => 0.04
	 *	            [Avg. position] => 5.2
	 *	            [Labels] =>  --
	 *	            [Quality score] => 6
	 *	        )
	 *
	 *	    [2] => Array
	 *	        (
	 *	            [Day] => 2015-05-02
	 *	            [Keyword state] => enabled
	 *	            [Keyword] => [cool math fun games]
	 *	            [Campaign] => Math Games
	 *	            [Ad group] => Math Games
	 *	            [Status] => below first page bid (First page cpc : 0.36)
	 *	            [Max. CPC] => 0.05
	 *	            [Clicks] => 1
	 *	            [Impressions] => 5
	 *	            [CTR] => 20.00%
	 *	            [Avg. CPC] => 0.05
	 *	            [Cost] => 0.05
	 *	            [Avg. position] => 4
	 *	            [Labels] =>  --
	 *	            [Quality score] => 6
	 *	        )
	 *	)
	 */
	public static function buildArrayFromCSV($filename){
		ini_set('memory_limit','256M');

		$filetype = PHPExcel_IOFactory::identify($filename);
		if ($filetype === 'CSV') {
			$delimiter = getCsvDelimiter($filename);
			$namedDataArray = array();
			$header = array();
			$fp = fopen($filename, 'r+');
			while (($data = fgetcsv($fp, 0, $delimiter)) !== false) {

				// Remove rows that have less than 2 rows
				if (count($data) < 2) {
					continue;
				}

				// Set header
				if (empty($header)) {

					// Avoid initial rows that have many empty cells
					$i = 0;
					foreach ($data as $value) {
						if (empty($value)) {
							$i++;
						}
					}
					if ($i > 4) {
						continue;
					}

					// Set headers
					foreach ($data as $value) {
						$header[] = clean_string($value);
					}
					continue;
				}

				// Remove any totals rows
				if (!is_numeric(substr(trim($data[0]), 0, 1))) {
					continue;
				}

				// Add cleaned strings
				$dataArray = array();
				foreach($data as $id => $value) {
					$dataArray[trim($header[$id])] = clean_string($value);
				}
				$namedDataArray[] = $dataArray;
			}

			return $namedDataArray;
		}

		// Set cache limit
		$cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_discISAM;
		PHPExcel_Settings::setCacheStorageMethod($cacheMethod);

		// Open file
		$objReader = PHPExcel_IOFactory::createReader($filetype);
		$objReader->setReadDataOnly(true);
		$objPHPExcel = $objReader->load($filename);
		$sheet = $objPHPExcel->getSheet(0);

		//  Get worksheet dimensions
		$highestRow = $sheet->getHighestDataRow();

		if ($highestRow > self::MAX_ROW_COUNT) {
			$objPHPExcel->disconnectWorksheets();
			unset($objPHPExcel);
			unset($objReader);
			throw new Exception('Sheet cannot have more than ' . number_format(self::MAX_ROW_COUNT) . ' rows', self::VALIDATION_ERROR_CODE);
		}

		$highestColumn = $sheet->getHighestDataColumn();
		$highestColumnIndex = PHPExcel_Cell::columnIndexFromString($sheet->getHighestColumn());

		// Ignore top rows by looking for first row where second column is not empty
		// This will give us the actual header row
		for ($row=1; $row <= $highestRow; $row++) {
			$contents = $sheet->getCellByColumnAndRow(1, $row)->getValue();
			if (!empty($contents)){
				$headerRow = $row;
				break;
			}
		}

		// Lets build an array with these headings instead of trying to find references later with "A" or "AB" etc
		// This way we now have a reference array like [A] => Site ID, [B] => Domain etc
		$leftTop = 'A'.$headerRow;
		$rightTop = $highestColumn.$headerRow;
		$headingsArray = $sheet->rangeToArray($leftTop.':'.$rightTop, null, false, false, true);
		$headingsArray = $headingsArray[$headerRow];

		// Setup placeholder for restructured array data from the table
		$namedDataArray = array();
		$id = 0;

		// Loop through each row and get its contents
		for ($row = ($headerRow+1); $row <= $highestRow; $row++) {

			// Check if the row has a valid date in the first col
			// If it doesnt then ignore it
			if (!is_numeric(substr($sheet->getCellByColumnAndRow(0, $row)->getValue(), 0, 1))){
				continue;
			}

			// Build an array of each cell in this row, up to $highestColumn
			// array ([1] => array( [A] => 321, [B] => mywebsite.com etc))
			$leftTop = 'A'.$row;
			$rightTop = $highestColumn.$row;
			$dataRow = $sheet->rangeToArray($leftTop.':'.$rightTop, null, true, true, true);

			// Lets just double check that this row has data. If it doesnt exist ignore it
			if ((isset($dataRow[$row]['A'])) && ($dataRow[$row]['A'] > '')) {

				$id = $id + 1;

				// Loop through the headings array we created earlier
				// This is where we will turn the non helpfull $dataRow keys from [A] into relevent [Header Title] etc
				foreach($headingsArray as $columnKey => $columnHeading) {
				    $namedDataArray[$id][$columnHeading] = $dataRow[$row][$columnKey];
				}
			}
		}

		$objPHPExcel->disconnectWorksheets();
		unset($objPHPExcel);
		unset($objReader);

		return $namedDataArray;
	}
}
