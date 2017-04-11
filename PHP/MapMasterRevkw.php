<?php

class MapMasterRevkw extends Eloquent {
	public static function getMapRevKwsForId($id, $withWeightLockedRows = true) {
		$query = 'SELECT m.id, m.typetag_id,
						 CASE WHEN m.keyword_id = 0 THEN "{{costkw}}" ELSE k.name END AS revkw,
						 (m.weight_end - (m.weight_start-1)) as weight,
						 m.keyword_id as keywordId,
						 m.weight_locked as locked
					FROM map_master_revkw m
						LEFT JOIN {alchemy_cloaker}.keyword k ON m.keyword_id = k.id
					WHERE m.map_master_id IN (';

		$values = array();
		if (is_array($id)) {
			$query .= implode(',', array_fill(0, count($id), '?'));
			$values = $id;
		}
		else {
			$query .= '?';
			$values[] = $id;
		}
		$query .= ")";

		if (!$withWeightLockedRows) {
			$query .= "\r\n\tAND m.weight_locked = 0";
		}

		$query .= "\r\nORDER BY m.typetag_id, (m.weight_end-m.weight_start) DESC, k.name";
		$revKws = DB::select(Functions::prefix($query), $values, 'map_master_revkw.by_map');

		$revKws = MapMasterHistoryRevkw::addOldWeightsToRevkws ($id, $revKws);

		return !empty($revKws) ? $revKws : false;
	}

	public static function weightLock($id, $status){
		$query = 'UPDATE map_master_revkw SET weight_locked = ? WHERE id = ?';
		$values = array($status, $id);

		$result = DB::update($query, $values);
		return !empty($result) ? $result : false;
	}

	public static function hasNewDataToSave($mapId, $rows){
		$revKws = self::getMapRevKwsForId($mapId);

		// If there are no existing keywords then we need to save them
		if (empty($revKws)){
			return true;
		}
		// If there are keywords then loop through them and compare
		$diffCollector = $revKws;
		foreach ($revKws as $key => $revKw){
			foreach ($rows as $row){
				if ($row['revkw'] == 'null'){
					$row['revkw'] = '';
				}
				if ($revKw->revkw == $row['revkw'] &&
					$revKw->typetag_id == $row['typetag_id'] &&
					$revKw->weight == $row['weight']){
					unset ($diffCollector[$key]);
				}
			}

		}

		if (count($diffCollector) == 0){
			return false;
		}

		return true;
	}

	public static function store($user, $mapMasterId, $rows) {
		if (empty($rows)) {
			throw new Exception('MapRevkw rows empty on store');
		}

		foreach ($rows as $rowId => $row) {
			$rows[$rowId] = (array) $row;
		}

		// First check to see if these revkw's already exist.
		// If so then just ignore all the rest of the saving.
		if (!self::hasNewDataToSave($mapMasterId, $rows)){
			return true;
		}

		$historyId = MapMasterHistory::store($mapMasterId);
		MapMasterHistoryRevkw::store ($mapMasterId, $historyId);

		$maprevDB = DB::table('map_master_revkw');
		$maprevDB->where('map_master_id', '=', $mapMasterId)->delete();

		$weightStart = 1;
		foreach ($rows as $row) {
			if (!$row['weight']) continue;

			$input = array(
				'id' => Functions::getRandID('map_master_revkw'),
				'map_master_id' => $mapMasterId,
				'typetag_id' => $row['typetag_id'],
				'keyword_id' => Keyword::getOrCreateKeywordId(trim($row['revkw'])),
				'weight_locked' => isset($row['weight_locked']) ? (int) $row['weight_locked'] : 0,
			);

			$input['weight_start'] = $weightStart;
			$weightStart += $row['weight'];
			$input['weight_end'] = $weightStart - 1;

			$maprevDB->insert($input);

		}

		return true;
	}

	public static function getMapData($user, $mapMasterId, $revkws, $start_date, $end_date, $withTotals = true) {
		if (empty($revkws)) {
			return array();
		}

		// Ensure the id is not 0 or empty array
		if (empty($mapMasterId)) {
			return array();
		}

		// Check that user has access to master map(s)
		if (is_array($mapMasterId)) {
			foreach ($mapMasterId as $key => $id) {
				if (!MapMaster::getMapMaster($id, $user)) {
					unset($mapMasterId[$key]);
				}
			}
			if (empty($mapMasterId)) {
				return array();
			}
		}
		else {
			$map = MapMaster::getMapMaster($mapMasterId, $user);
			if (!$map) {
				return array();
			}
		}

		$typetagIds = array();
		foreach ($revkws as $k => $v) {
			$typetagIds[] = $v->typetag_id;
		}

		$typetagFillers = implode(',', array_fill(0, count($typetagIds), '?'));
		$mapMasterFillers = is_array($mapMasterId) ? implode(',', array_fill(0, count($mapMasterId), '?')) : '?';

		date_default_timezone_set($user ? $user->timezone : 'Etc/UTC');
		$values = array(AdgroupSetting::TYPE_CPC, strtotime($start_date), strtotime($end_date . ' 23:59:59'));
		$values = array_merge($values, $typetagIds);

		if (is_array($mapMasterId)) {
			$values = array_merge($values, $mapMasterId);
		}
		else {
			$values[] = $mapMasterId;
		}

		$revenue = $user && $user->hasAccess('admin') ? 'IF(revshare IS NULL, NULL, revenue / revshare * 100)' : 'revenue';

		$query = "	SELECT 	*
					FROM 	(
							SELECT  typetag_id,
									rev_keyword_id,
									SUM(potential_clicks) as potential_clicks,
									SUM(clicks_cost) as clicks_cost,
									SUM(impressions_count) as impressions_count,
									SUM(clicks_parked) as clicks_parked,
									SUM(clicks_revenue) as clicks_revenue,
									IF(SUM(clicks_landing) != 0, FORMAT(ROUND(SUM(clicks_parked)/SUM(clicks_landing), 2)*100, 0), '0%') AS lp_ctr,
									IF(SUM(potential_clicks) != 0, concat(FORMAT(ROUND(SUM(COALESCE(clicks, clicks_revenue))/SUM(potential_clicks), 2)*100, 0), '%'), '0%') AS po_ctr,
									IF(SUM(impressions_count) != 0, concat(FORMAT(ROUND(SUM(clicks_revenue)/SUM(impressions_count), 2)*100, 0), '%'), '0%') AS i_ctr,
									SUM(clicks) as verified_clicks,
									ROUND(SUM($revenue), 2) AS verified_revenue,
									IF(SUM(revenue_estimate > 0) > 0, ROUND(SUM($revenue IS NOT NULL AND revenue_estimate > 0) / SUM(revenue_estimate > 0) * 100, 0), 100) as revenue_incomplete_pct,
									IF(SUM(clicks) != 0, SUM($revenue)/SUM(clicks), 0) as rpc,
									IF(SUM(potential_clicks) != 0, SUM($revenue)/SUM(potential_clicks), 0) as epc,
									IF(SUM($revenue) > 0,
										(SUM($revenue)/SUM(potential_clicks)) / IF(cost IS NOT NULL, cost / potential_clicks, ads.value),
										0) as r_ratio
							FROM 	{alchemy_data}.click_summary_revkw c
								JOIN adgroup ad ON ad.id = c.adgroup_id
								LEFT JOIN adgroup_setting ads ON ad.id = ads.adgroup_id AND ads.type = ?
							WHERE 	hour BETWEEN ? AND ?
								AND c.typetag_id IN ($typetagFillers)
								AND c.adgroup_id IN (
									  SELECT m.adgroup_id
									  FROM map m
									    JOIN map_map_master mm on m.map_id = mm.map_id
									  WHERE mm.map_master_id IN ($mapMasterFillers)
									)
							GROUP BY typetag_id, rev_keyword_id WITH ROLLUP
						) sub
					WHERE 	NOT(rev_keyword_id IS NULL OR typetag_id IS NULL) OR (rev_keyword_id IS NULL AND typetag_id IS NULL)";

		$results = DB::connection('mysql-slave')->select(Functions::prefix($query), $values, 'map_master_revkw.summary_stats');
		if (empty($results)) {
			return array();
		}

		// Set totals row
		$totals = last($results);
		end($results);
		unset($results[key($results)]);

		// Reorder data based on maprevkws array
		$data = array();
		foreach ($revkws as $key => $kw) {
			$found = false;
			foreach ($results as $row) {
				if ((int) $kw->keywordId === (int) $row->rev_keyword_id && (int) $kw->typetag_id === (int) $row->typetag_id) {
					$row->unconfirmed = $row->clicks_cost && $row->revenue_incomplete_pct < 100;
					$data[$key] = $row;
					$found = true;
					break;
				}
			}

			// If the row is not found, add in a default row with 0's to represent row without data
			if (!$found) {
				$data[$key] = (object) array(
					'rev_keyword_id' => $kw->keywordId,
					'typetag_id' => $kw->typetag_id,
					'potential_clicks' => 0,
					'clicks_cost' => 0,
					'impressions_count' => 0,
					'clicks_parked' => 0,
					'clicks_revenue' => 0,
					'lp_ctr' => '0%',
					'po_ctr' => '0%',
					'i_ctr' => '0%',
					'verified_clicks' => 0,
					'verified_revenue' => 0,
					'revenue_incomplete_pct' => 100,
					'unconfirmed' => false,
					'rpc' => 0,
					'epc' => 0,
				);
			}
		}

		if ($withTotals) {
			$data[] = $totals;
		}
		return $data;
	}

	public static function generageBulkLookupHref($user, $id) {
		$href = '/bid/search/bulk';
		$revKws = self::getMapRevKwsForId($id);
		if (empty($revKws)) {
			return $href;
		}

		$keywords = array();
		foreach ($revKws as $keyword) {
			$keywords[$keyword->revkw] = true;
		}

		return $href . '?adterms=' . urlencode(implode("\r\n", array_keys($keywords)));
	}
}
