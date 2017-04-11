<?php

ini_set('memory_limit','256M');
date_default_timezone_set('America/Vancouver');

$options = getopt('', array('env:'));
$isProd = !empty($options['env']) && $options['env'] === 'production';

$current_time = time();
$script_name = basename($argv[0]);
$my_lock = false;

// Connect to master
$default_settings = require(dirname(__FILE__).'/../config/database.php');
$environment_settings = require(dirname(__FILE__).'/../config/' . ($isProd ? 'production' : 'local') . '/database.php');

$db_settings = array('connections' => array(
	'mysql' => array_merge($default_settings['connections']['mysql'], $environment_settings['connections']['mysql']),
	'mysql-slave' => array_merge($default_settings['connections']['mysql-slave'], $environment_settings['connections']['mysql-slave']),
));

try {
	if (!($db_master = mysql_connect($db_settings['connections']['mysql']['host'], $db_settings['connections']['mysql']['username'], $db_settings['connections']['mysql']['password']))) {
		throw new Exception('Error connecting');
	}
	if (!mysql_select_db('alchemy_data', $db_master)) {
		throw new Exception('Error selecting db for master ' . mysql_error($db_master));
	}

	// Connect to slave
	if (!($db_slave = mysql_connect($db_settings['connections']['mysql-slave']['host'], $db_settings['connections']['mysql-slave']['username'], $db_settings['connections']['mysql-slave']['password']))) {
		throw new Exception('Error connecting to slave');
	}
	if (!mysql_select_db('alchemy_data', $db_slave)) {
		throw new Exception('Error selecting db for slave ' . mysql_error($db_slave));
	}

	if ($isProd) {
		// Check that slave is not out of sync with master
		$result = mysql_query('SHOW SLAVE STATUS', $db_slave);
		if (!$result || !mysql_num_rows($result)) {
			throw new Exception('Unable to get slave status ' . mysql_error($db_slave));
		}
		$slave_status = mysql_fetch_assoc($result);
		if (empty($slave_status)) {
			throw new Exception('Slave status is empty');
		}

		// Check that slave is running
		if ($slave_status['Slave_SQL_Running'] !== 'Yes') {
			$message = 'Slave is not running: ' . $slave_status['Slave_SQL_Running'];
			echo $message."\n";
			trigger_error($message, E_USER_WARNING);
			exit();
		}

		// Check that slave is within 60 seconds of master
		if ($slave_status['Seconds_Behind_Master'] > 60) {
			throw new Exception('Slave is too far behind master: ' . $slave_status['Seconds_Behind_Master'] .'s behind.');
		}
	}

	// Set lock
	if (!($getLock = mysql_query('UPDATE alchemy.script SET state = 1 WHERE script_name = "'.mysql_real_escape_string($script_name, $db_master).'" AND state = 0;', $db_master))) {
		throw new Exception('Error running lock command: '.mysql_error($db_master));
	}
	if (mysql_affected_rows($db_master) == 0) {
		throw new Exception('Error obtaining lock');
	}
	$my_lock = true;

	// Get previous run settings
	$getSettings = mysql_query('SELECT last_processed FROM alchemy.script WHERE script_name = "'.mysql_real_escape_string($script_name, $db_slave).'";', $db_slave);
	if (!$getSettings || (mysql_num_rows($getSettings) == 0)) {
		throw new Exception('Error getting settings: '.mysql_error($db_slave));
	}
	list($last_processed) = mysql_fetch_row($getSettings);

	$process_hour = $last_processed;
	$process_till = strtotime(date('Y-m-d H:59:59', $current_time));

	// Process previous hour if we are processing within the first 15 minutes of the next hour
	$current_minute = ((int) date('i', $current_time));
	if ($current_time - $process_hour <= 3600 + $current_minute && $current_minute <= 15) {
		$process_hour -= 3600;
	}

	// Check if the max process hour back to revenue allocation is older than
	// process hour and swap if it is
	$max_process_hour = getMaxProcessHour(time());
	if ($process_hour > $max_process_hour) {
		$process_hour = $max_process_hour;
	}

	$selectSQL = <<<SQL
SELECT
	UNIX_TIMESTAMP(FROM_UNIXTIME(created_at, '%Y-%m-%d %H:00:00')) AS hour,
	user_id,
	account_id,
	adgroup_id,
	typetag_id,
	device_type,
	matchtype,
	template_id,
	landing_template_id,
	creative_id,
	parked_id,
	ad_displayurl,
	SUM(impression) as impressions_count,
	COUNT(id) as potential_clicks,
	SUM(status) as clicks_cost,
	COUNT(click_landing) as clicks_landing,
	COUNT(click_parked) as clicks_parked,
	COUNT(click_revenue) as clicks_revenue,
	COALESCE(IF(SUM(revenue_estimate) > 0, SUM(revenue_estimate), COUNT(revenue_estimate)),0) as revenue_estimate
FROM
(
	SELECT 	c.id,
			c.created_at,
			c.status,
			l.click_cost_id as click_landing,
			i.click_cost_id as click_parked,
			COALESCE(i.impression,0) as impression,
			r.id as click_revenue,
			r.revenue_estimate,
			COALESCE(a.user_id,0) AS user_id,
			COALESCE(acs.account_id,0) AS account_id,
			c.adgroup_id as adgroup_id,
			c.typetag_id as typetag_id,
			c.device_type as device_type,
			COALESCE(c.matchtype,'') as matchtype,
			c.template_id as template_id,
			c.landing_template_id as landing_template_id,
			COALESCE(c.creative,'') as creative_id,
			c.parked_id as parked_id,
			if(COALESCE(LOWER(r.ad_displayurl), '') REGEXP '[^\/]+\/.*',
				SUBSTRING_INDEX(if(COALESCE(LOWER(r.ad_displayurl), '') REGEXP '^www\.', SUBSTR(COALESCE(LOWER(r.ad_displayurl), ''), 5), COALESCE(LOWER(r.ad_displayurl), '')), '/', 1),
				if(COALESCE(LOWER(r.ad_displayurl), '') REGEXP '^www\.', SUBSTR(COALESCE(LOWER(r.ad_displayurl), ''), 5), COALESCE(LOWER(r.ad_displayurl), ''))
			) as ad_displayurl
	FROM
		alchemy_data.click_cost AS c USE INDEX (summary_generation)
		LEFT JOIN alchemy_data.click_landing AS l ON l.click_cost_id = c.id
		LEFT JOIN alchemy_data.click_impression AS i ON i.click_cost_id = c.id
		LEFT JOIN alchemy_data.click_revenue AS r ON r.click_cost_id = c.id
		LEFT JOIN alchemy.adgroup AS ag ON ag.id = c.adgroup_id
		LEFT JOIN alchemy.account_story acs ON ag.account_story_id = acs.id
		LEFT JOIN alchemy.account a ON acs.account_id = a.id
		LEFT JOIN alchemy.users u ON a.user_id = u.id
	WHERE
		c.created_at BETWEEN $process_hour AND $process_till
		AND c.potential_click = 1
) tester
GROUP BY
	hour,
	user_id,
	account_id,
	adgroup_id,
	typetag_id,
	device_type,
	matchtype,
	template_id,
	landing_template_id,
	creative_id,
	parked_id,
	ad_displayurl
;
SQL;
	echo "SELCTING FOR ".date('Y-m-d H:i:s', $process_hour).' TO '.date('Y-m-d H:i:s', $process_till).":\n";
	// echo "$selectSQL\n";
	$result = mysql_query($selectSQL, $db_slave);
	if (!$result) {
		throw new Exception('Error selecting SQL from slave DB: '.mysql_error($db_slave));
	}

	$num_rows = mysql_num_rows($result);
	if (!$num_rows) {
		echo"NO SUMMARY DATA FOR ".date('Y-m-d H:i:s', $process_hour).' TO '.date('Y-m-d H:i:s', $process_till).":\n";
	}
	else {
		// Assemble rows
		$rows = array();
		$displayUrls = array();
		$i = 0;
		while ($row = mysql_fetch_assoc($result)) {
			$rows[] = $row;

			$i++;
			if ($i < 10000) continue;
			$i = 0;

			$insert_query = generateInsertQuery($db_master, $rows);
			// echo "$insert_query\n";
			if (!mysql_query($insert_query, $db_master)) {
				throw new Exception('Error inserting selected value from slave into master: ' . mysql_error($db_master));
			}

			echo "\n\nAFFECTED ROWS: ".mysql_affected_rows($db_master)."\n";
			$rows = array();
		}

		if (!empty($rows)) {
			$insert_query = generateInsertQuery($db_master, $rows);
			// echo "$insert_query\n";
			if (!mysql_query($insert_query, $db_master)) {
				throw new Exception('Error inserting selected value from slave into master: ' . mysql_error($db_master));
			}

			echo "\n\nAFFECTED ROWS: ".mysql_affected_rows($db_master)."\n";
		}
	}

	if (!mysql_query('UPDATE alchemy.script SET last_processed = '.strtotime(date('Y-m-d H:00:00', $process_till)).', state = 0, last_executed = '.$current_time.' WHERE script_name = "'.mysql_real_escape_string($script_name, $db_master).'";', $db_master)) {
		throw new Exception('Could not set finalized state: '.mysql_error($db_master));
	}
}
catch (Exception $e) {
	echo $e->getMessage()."\n";
	trigger_error($e->getMessage(), E_USER_WARNING);
	if ($isProd) {
		@mail('it-priority@weblogix.com', 'Script Failure - ' . $script_name, $e->getMessage());
	}
	if ($my_lock) {
		if (!mysql_query('UPDATE alchemy.script SET state = 2, last_executed = '.$current_time.', message = "' . $e->getMessage() . '" WHERE script_name = "'.mysql_real_escape_string($script_name, $db_master).'";', $db_master)) {
			trigger_error('Could not set error flag', E_USER_ERROR);
		}
	}
	exit;
}

function generateInsertQuery($db_master, $rows) {
	$insert_query = "INSERT INTO alchemy_data.click_summary (hour, user_id, account_id, adgroup_id, typetag_id, device_type, matchtype, template_id, landing_template_id, creative_id, parked_id, ad_displayurl, impressions_count, potential_clicks, clicks_cost, clicks_landing, clicks_parked, clicks_revenue, revenue_estimate)\n VALUES";
	for ($i = 0; $i < count($rows); $i++) {
		foreach ($rows[$i] as &$rowvalue){
			$rowvalue = mysql_real_escape_string($rowvalue, $db_master);
		}
		unset($rowvalue);
		$insert_query .= "\n('" . implode("','", $rows[$i]) . "')" . ($i == count($rows) - 1 ? '' : ',');
	}
	$insert_query .= "\nON DUPLICATE KEY UPDATE impressions_count = VALUES(impressions_count), potential_clicks = VALUES(potential_clicks), clicks_cost = VALUES(clicks_cost), clicks_landing = VALUES(clicks_landing), clicks_parked = VALUES(clicks_parked), clicks_revenue = VALUES(clicks_revenue), revenue_estimate = VALUES(revenue_estimate)";
	return $insert_query;
}

function getMaxProcessHour($time) {
	$timezone = date_default_timezone_get();

	date_default_timezone_set('America/Vancouver');

	$datePacific = date('Y-m-d', $time);

	date_default_timezone_set('Etc/UTC');

	$dateUtc = date('Y-m-d', $time);
	$hourUtc = date('H', $time);

	// Generate summary back up until revenue allocation occurs
	if ($hourUtc >= 13) {
		$process_hour = strtotime(date('Y-m-d', $datePacific != $dateUtc ? $time - 86400 : $time));
	}
	else {
		$process_hour = strtotime(date('Y-m-d', $time - 86400));
	}

	date_default_timezone_set($timezone);

	return $process_hour - (86400 * 14);
}
