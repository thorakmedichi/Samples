<?php

class VPN extends Eloquent {


    // Constant list that gets used in this Model
    // but is also called on extensively in other
    // Models and Controllers and Views
    const LOAD_METHOD_MANUAL = 'manual';
    const LOAD_METHOD_RPI_CLONE = 'rpi-clone';
    const LOAD_METHOD_IMAGE = 'image';
    const LOAD_METHOD_THEPIHUT = 'thepihut';
    const LOAD_METHOD_PISUPPLY = 'pisupply';

    const SD_CARD_SAMSUNG = 'samsung';
    const SD_CARD_KINGSTON = 'kingston';
    const SD_CARD_SANDISK = 'sandisk';
    const SD_CARD_SANDISK_HCI = 'sandisk-HCI';
    const SD_CARD_MICRO_SAMSUNG = 'microsamsung';

    const PSU_ORIGINAL = 'original';
    const PSU_DETACHED = 'detached';
    const PSU_THEPIHUT = 'thepihut';
    const PSU_PISUPPLY = 'pisupply';

    const PAID_TIMELINE_DAILY = 'daily';
    const PAID_TIMELINE_WEEKLY = 'weekly';
    const PAID_TIMELINE_MONTHLY = 'monthly';

    const STATE_NOT_READY = '';
    const STATE_READY = 'Ready';
    const STATE_RETURNING = 'Returning';
    const STATE_RETURNED = 'Returned';
    const STATE_UNREACHABLE = 'Unreachable';
    const STATE_TECH_SUPPORT = 'Tech Support';
    const STATE_KILLING = 'Killing';
    const STATE_LOST = 'Lost';
    const STATE_REUSE = 'Reuse';
    const STATE_SHIPPED = 'Shipped';
    const STATE_COLLECTIONS = 'Collections';
    const STATE_DOWN_MAJOR = 'Down';
    const STATE_DOWN_MINOR = 'Down';
    const STATE_VPN_ISSUES = 'VPN Issues';
    const STATE_VPN_ISSUE = 'VPN Issues';
    const STATE_IP_CHANGED = 'IP Changed';
    const STATE_RESETTING = 'Resetting';
    const STATE_ACTIVATING = 'Activating';
    const STATE_ACTIVATED = 'Activated';
    const STATE_ACTIVATED_COMPONENTS = 'Activated, Needs Components';

    const PAYMENT_METHOD_PAYPAL = 'paypal';
    const PAYMENT_METHOD_ZOOMBUCKS = 'zoombucks';
    const PAYMENT_METHOD_HERMAN = 'herman';
    const PAYMENT_METHOD_UNKNOWN = 'unknown';
    const PAYMENT_METHOD_AMAZON = 'amazon';

    const SHIPPING_METHOD_USPS = 'usps';
    const SHIPPING_METHOD_CANADAPOST = 'canadapost';

    const PLACEHOLDER = 10;
    const PLACEHOLDER_IDENTITY_RECLAIMING = 20;

    /**
     * Get a list of all our VPN's registered in the system
     * @param     object     $user           The user object of the current person logged in. Security layer
     * @param     boolean    $active         Do we want the active or inactive VPN's specifically, or all?
     * @param     boolean    $withStories    Return data about the VPN story site as well?
     * @param     integer    $accountId      List only VPN's connected to a specific client account
     * @return                               The database collection
     */
    public static function getAll($user = null, $active = null, $withStories = false, $accountId = null) {
        $cNotReady = self::getStateIdByName($user, self::STATE_NOT_READY);
        $cReturning = self::getStateIdByName($user, self::STATE_RETURNING);
        $cShipped = self::getStateIdByName($user, self::STATE_SHIPPED);
        $cReturned = self::getStateIdByName($user, self::STATE_RETURNED);
        $cReuse = self::getStateIdByName($user, self::STATE_REUSE);
        $cCollections = self::getStateIdByName($user, self::STATE_COLLECTIONS);
        $cLost = self::getStateIdByName($user, self::STATE_LOST);
        $cActivating = self::getStateIdByName($user, self::STATE_ACTIVATING);
        $cActivated = self::getStateIdByName($user, self::STATE_ACTIVATED);

        $cDownMajor = self::getStateIdByName($user, self::STATE_DOWN_MAJOR);
        $cKilling = self::getStateIdByName($user, self::STATE_KILLING);
        $cUnreachable = self::getStateIdByName($user, self::STATE_UNREACHABLE);

        $cActivatedComponents = self::getStateIdByName($user, self::STATE_ACTIVATED_COMPONENTS);
        $cDownMinor = self::getStateIdByName($user, self::STATE_DOWN_MINOR);
        $cResetting = self::getStateIdByName($user, self::STATE_RESETTING);
        $cVpnIssue = self::getStateIdByName($user, self::STATE_VPN_ISSUE);
        $cTechSupport = self::getStateIdByName($user, self::STATE_TECH_SUPPORT);
        $cIpChanged = self::getStateIdByName($user, self::STATE_IP_CHANGED);

        $cReady = self::getStateIdByName($user, self::STATE_READY);

        $query = "  SELECT DISTINCT

                            ## VPN
                            ## --------------------------------
                            v.*,
                            v.id AS vpnId,
                            CONCAT (vl.city,', ',vl.state) AS location,
                            IF (v.command_bit > 0, $cResetting,
                                IF (v.verified = 0, $cActivating, ## Activating...

                                    CASE v.state
                                        WHEN 'Killing' THEN $cKilling
                                        WHEN 'Unreachable' THEN $cUnreachable
                                        WHEN 'Tech Support' THEN $cTechSupport
                                        WHEN 'Returning' THEN $cReturning
                                        WHEN 'Shipped' THEN $cShipped
                                        WHEN 'Returned' THEN $cReturned
                                        WHEN 'Reuse' THEN $cReuse
                                        WHEN 'Collections' THEN $cCollections
                                        WHEN 'Lost' THEN $cLost
                                        WHEN 'Activated' THEN
                                            IF (vi.pi_id IS NULL OR vi.psu_id IS NULL OR vi.sd_id IS NULL OR vi.cable_id IS NULL, $cActivatedComponents, $cActivated)
                                        WHEN 'Ready' THEN
                                            CASE
                                                WHEN (COALESCE(v.date_lastcall_in, 0) < DATE_SUB(NOW(), INTERVAL 9 MINUTE)) THEN $cDownMajor ## Call home more than 9min ago, RED
                                                WHEN (COALESCE(v.date_lastcall_out, 0) < DATE_SUB(NOW(), INTERVAL 11 MINUTE)) THEN $cVpnIssue ## Call out more than 10min ago, YELLOW
                                                WHEN (COALESCE(v.date_lastcall_in, 0) < DATE_SUB(NOW(), INTERVAL 5 MINUTE)) THEN $cDownMinor ## Call home more than 5min ago, YELLOW
                                                WHEN vl.ip_address<>v.ip_lastcall THEN $cIpChanged ## IP changed, YELLOW
                                                ELSE $cReady ## GREEN
                                            END
                                        ELSE $cNotReady
                                    END
                                )
                            ) AS vpn_status,

                            CASE
                                WHEN (COALESCE(v.date_lastcall_out, 0) < DATE_SUB(NOW(), INTERVAL 11 MINUTE)) THEN
                                    IF(v.date_lastcall_out IS NOT NULL, ROUND((UNIX_TIMESTAMP()-UNIX_TIMESTAMP(v.date_lastcall_out))/3600,1), ROUND((UNIX_TIMESTAMP()-UNIX_TIMESTAMP(v.date_activated))/3600,1))
                                WHEN (COALESCE(v.date_lastcall_in, 0) < DATE_SUB(NOW(), INTERVAL 9 MINUTE)) THEN
                                    IF(v.date_lastcall_in IS NOT NULL, ROUND((UNIX_TIMESTAMP()-UNIX_TIMESTAMP(v.date_lastcall_in))/3600,1), ROUND((UNIX_TIMESTAMP()-UNIX_TIMESTAMP(v.date_activated))/3600,1))
                            END AS down_time, ## In hours

                            ## VPN Lead
                            ## --------------------------------
                            vl.isp,
                            CONCAT (vl.city,', ',vl.state) AS location,
                            vl.name AS owner,
                            INET_NTOA(vl.ip_address) as ip,

                            ## VPN Info
                            ## --------------------------------
                            vi.paid_until,
                            IF(vi.paid_timeline <> 'weekly', '',
                                IF (ISNULL(date_activated_weekly), '',
                                    LEAST(1, callcount_weekly/CEILING((UNIX_TIMESTAMP()-UNIX_TIMESTAMP(date_activated_weekly))/240))
                                )
                            ) AS weekly_uptime_this,
                            IF(vi.paid_timeline <> 'weekly', '',
                                IF (ISNULL(date_activated_weekly), '',
                                     v.uptime_lastweek/100
                                )
                            ) AS weekly_uptime_last,
                            IF(date_activated>=date_shipped,
                                LEAST(1, callcount_in/CEIL((UNIX_TIMESTAMP()-UNIX_TIMESTAMP(date_activated))/240)),
                                NULL
                            ) AS uptime_pct,

                            ## Identity Info
                            ## --------------------------------
                            i.*,
                            i.id AS identityId,
                            i.account_type AS identity_account_type,
                            i.status AS identity_status,
                            v.id AS id,

                            ## Account Info
                            ## --------------------------------
                            a.type AS account_type,
                            a.name AS account_name,
                            a.dormant_at AS account_dormant_at,
                            a.suspended_at AS account_suspended_at,

                            CONCAT(u.first_name, ' ', u.last_name, ' (', sup.name, ')') AS user_name,
                            REPLACE(s.domain,'.com','') AS domain,
                            s.id AS story_id, 

                            vs.score_percent,
                            vs.id as score_id

                    FROM    {alchemy_identity}.vpn v
                        JOIN {alchemy_identity}.vpn_info vi ON v.id = vi.vpn_id
                        LEFT JOIN {alchemy_identity}.vpn_lead vl ON vi.vpn_lead_id = vl.id
                        LEFT JOIN (
                                    SELECT * 
                                    FROM ( 
                                            SELECT *
                                            FROM {alchemy_identity}.vpn_score 
                                            ORDER BY created_at DESC    
                                         ) vs2
                                    GROUP BY vs2.vpn_id
                                    ) as vs ON vs.vpn_id = v.id
                        LEFT JOIN {alchemy_identity}.identity2 i ON v.id = i.vpn_id
                        LEFT JOIN account a ON i.account_id = a.id
                        LEFT JOIN users u ON a.user_id = u.id
                        LEFT JOIN suppliers sup ON u.supplier_id = sup.id
                        LEFT JOIN account_story acs ON a.id = acs.account_id
                        LEFT JOIN story s ON acs.story_id = s.id

                    WHERE   v.status = 1";

        $values = array();

        if (isset($active)) {
            $query .= "\r\n\tAND v.state " . (!$active ? '!' : '') . "= ?";
            $values[] = 'Ready';
        }

        if($accountId !== null){
            $query .= "\n\tAND a.id = ?";
            $values[] = $accountId;
        }

        if ($user && !$user->hasAnyAccess(array('admin', 'vpn_user', 'symbiosis_vpn_manager'))) {
            if ($user->hasAccess('supervisor')) {
                $query .= "\n\tAND u.supplier_id = ?";
                $values[] = $user->supplier_id;
            }
            else {
                $query .= "\n\tAND u.id = ?";
                $values[] = $user->id;
            }
        }

        $query .= "\r\nORDER BY vpn_status, v.id";

        DB::connection('mysql')->getPdo()->exec("SET time_zone = 'America/Vancouver';");
        $vpns = DB::select(Functions::prefix($query), $values, 'vpn.all');

        return !empty($vpns) ? $vpns : false;
    }

    /**
     * Genereate an array of key => val options for HTML select
     * @param     object    $user      The user object of the currently logged in user. Security layer.
     * @param     boolean   $active    Return of Active, Inactive or all VPN's
     * @param     integer   $id        The id of a specific VPN
     * @return    array                Associative array of VPN's
     */
    public static function getSelectValues($user = null, $active = null, $id = null) {
        $ids = array('' => '');
        if ($id) {
            $vpn = self::getVpn($id);
            if ($vpn) {
                $ids[$id] = $id . ' | ' . $vpn->location;
            }
        }

        $vpns = self::getAll($user, $active);
        if (empty($vpns)) {
            return $ids;
        }
        foreach ($vpns as $vpn) {
            $ids[$vpn->vpnId] = $vpn->vpnId . ' | ' . $vpn->location;
        }

        asort($ids);
        return $ids;
    }

    /**
     * Get the values we need to display user actionable data on the dashboard
     * @param     object    $user    The user object of the currently logged in user. Security layer.
     * @return    array              Multidimensional associative array of all the values for this users VPN's
     */
    public static function getDashboardValues($user) {
        if (App::environment() === 'local') {
            DB::statement("SET time_zone = 'America/Vancouver'");
        }

        $vpns = self::getAll($user, null, false);

        $groupedVpns = array();
        $order = 0;
        foreach ($vpns as $vpn){
            if (!isset($groupedVpns[$vpn->id]['order'])) {
                $groupedVpns[$vpn->id]['order'] = $order;
                $order++;
            }

            $groupedVpns[$vpn->id]['vpn_id'] = $vpn->id;
            $groupedVpns[$vpn->id]['status'] = $vpn->vpn_status;
            $groupedVpns[$vpn->id]['isp'] = $vpn->isp;
            $groupedVpns[$vpn->id]['location'] = $vpn->location;
            $groupedVpns[$vpn->id]['owner'] = $vpn->owner;
            $groupedVpns[$vpn->id]['ip'] = $vpn->ip;
            $groupedVpns[$vpn->id]['uptime_percent'] = $vpn->uptime_pct;
            $groupedVpns[$vpn->id]['uptime_lastweek'] = $vpn->uptime_lastweek;
            $groupedVpns[$vpn->id]['uptime_lastday'] = $vpn->uptime_lastday;
            $groupedVpns[$vpn->id]['weekly_uptime_this'] = $vpn->weekly_uptime_this;
            $groupedVpns[$vpn->id]['weekly_uptime_last'] = $vpn->weekly_uptime_last;
            $groupedVpns[$vpn->id]['downtime'] = $vpn->down_time;
            $groupedVpns[$vpn->id]['paid_until'] = $vpn->paid_until;
            $groupedVpns[$vpn->id]['grade'] = VpnScore::getGrade($vpn->score_percent);
            $groupedVpns[$vpn->id]['score_percent'] = $vpn->score_percent;

            $groupedVpns[$vpn->id][$vpn->account_type]['account_id'] = $vpn->account_id;
            $groupedVpns[$vpn->id][$vpn->account_type]['status'] = $vpn->status;
            $groupedVpns[$vpn->id][$vpn->account_type]['user_name'] = $vpn->user_name;
            $groupedVpns[$vpn->id][$vpn->account_type]['account_name'] = $vpn->account_name;

            $groupedVpns[$vpn->id][$vpn->account_type]['state'] = Account::STATE_READY;
            if (!empty($vpn->account_suspended_at)) {
                $groupedVpns[$vpn->id][$vpn->account_type]['state'] = Account::STATE_SUSPENDED;
            }
            else if (!empty($vpn->account_dormant_at)) {
                $groupedVpns[$vpn->id][$vpn->account_type]['state'] = Account::STATE_DORMANT;
            }

            if (empty($vpn->account_id) && !empty($vpn->identity_account_type)){
                $groupedVpns[$vpn->id][$vpn->identity_account_type]['account_id'] = 'Placeholder';
                $groupedVpns[$vpn->id][$vpn->identity_account_type]['user_name'] = 'Placeholder';
                $groupedVpns[$vpn->id][$vpn->identity_account_type]['state'] = in_array($vpn->identity_status, array('reclaiming', 'reclaimed')) ? VPN::PLACEHOLDER_IDENTITY_RECLAIMING : VPN::PLACEHOLDER;
                $groupedVpns[$vpn->id][$vpn->identity_account_type]['account_name'] = '';
            }
            else {

                if (!empty($vpn->story_id)) {
                    if (!isset($groupedVpns[$vpn->id][$vpn->account_type]['domains'])){ // Create our holder
                        $groupedVpns[$vpn->id][$vpn->account_type]['domains'][$vpn->story_id] = $vpn->domain;
                    } else {
                        if (is_array($groupedVpns[$vpn->id][$vpn->account_type]['domains'])){ // Append to array
                            $groupedVpns[$vpn->id][$vpn->account_type]['domains'][$vpn->story_id] = $vpn->domain;
                        }
                    }
                }
            }
        }

        usort($groupedVpns, function($a, $b) {
            return $a['order'] < $b['order'] ? -1 : 1;
        });

        return $groupedVpns;
    }

    /**
     * Get VPN's specific to an account
     * These VPN's belong to many users in many security levels throughout each organizations various accounts
     * @param     object    $user         The user object of the person currently logged in. Security layer.
     * @param     integer   $accountId    The account ID that the current user wants access too.
     * @return                            Database collection of results
     */
    public static function getAccountVpns($user, $accountId = null){
        $query = "SELECT DISTINCT a.id as acct_id, a.name, a.type, v.state as vpn_state, s.domain,
                            IF(v.date_shipped IS NULL, 0, ## BLANK if not shipped
                                IF (v.state = 'Ready',
                                    CASE
                                        WHEN (COALESCE(v.date_lastcall_in, 0) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) THEN 0
                                        WHEN (COALESCE(v.date_lastcall_out, 0) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) THEN 0
                                        ELSE 1
                                    END,
                                0)
                            ) as status,
                            v.date_lastcall_in as vpn_date_lastcall_in, v.date_lastcall_out as vpn_date_lastcall_out, v.id
                    FROM {alchemy}.account a
                    JOIN {alchemy}.account_story acs ON acs.account_id = a.id
                    JOIN {alchemy}.story s ON s.id = acs.story_id
                    LEFT JOIN {alchemy_identity}.identity2 i ON i.account_id = a.id
                    LEFT JOIN {alchemy_identity}.vpn v ON v.id = i.vpn_id
                    LEFT JOIN {alchemy}.users u ON u.id = a.user_id
                    WHERE i.status = ?
                        AND a.suspended_at IS NULL
                        AND s.reclaimed_at IS NULL
                        AND v.status = 1";

        $values = array('ready');

        // If there is an account ID make sure 
        // we only get those VPN's
        if($accountId !== null){
            $query .= "\n\tAND acs.id = ?";
            $values[] = $accountId;
        }

        // If the user has elevated security priveledges let them
        // query data on ALL VPN's in this account regardless of 
        // supplier security level
        if (!$user->hasAnyAccess(array('admin', 'vpn_user'))) {

            $query .= "\n\tAND a.dormant_at IS NULL";

            if ($user->hasAccess('supervisor')) {
                $query .= "\n\tAND u.supplier_id = ?";
                $values[] = $user->supplier_id;
            }
            else {
                $query .= "\n\tAND u.id = ?";
                $values[] = $user->id;
            }
        }

        $query .= "\n\tORDER BY v.status DESC, v.id";

        // Get the results
        $results = DB::select(Functions::prefix($query), $values);
        if (empty($results)) {
            return false;
        }

        $vpns = array();
        foreach ($results as $row) {
            if (!isset($vpns[$row->acct_id])) {
                $vpns[$row->acct_id] = $row;
            }
            if (!isset($vpns[$row->acct_id]->domains)) {
                $vpns[$row->acct_id]->domains = array($row->domain);
                unset($vpns[$row->acct_id]->domain);
            }
            else {
                $vpns[$row->acct_id]->domains[] = $row->domain;
            }
        }

        $results = array_values($vpns);

        return $accountId ? $results[0] : $results;
    }

    /**
     * Get our shell shocked VPNS
     * @return        Database collection
     */
    public static function getShellShockVPNs() {
        $result = DB::select(Functions::prefix('SELECT * FROM {alchemy_identity}.vpn WHERE status = 1 AND state = ? AND shell_shock_patch = 0'), array('Ready'), 'vpn.shell_shock');
        return !empty($result) ? $result : false;
    }

    /**
     * Get the time of the last call home event for VPN's
     * @param     object    $user         The user object of the currently logged in user. Security layer
     * @param     integer   $accountId    The account ID we want to restrict the list too
     * @return    integer                 Unix timestamp value of VPN last call home
     */
    public static function getAccountVpnLastCall($user, $accountId){
        $vpn = self::getAccountVpns($user, $accountId);

        if ($vpn) {
            date_default_timezone_set('America/Vancouver');
            $callcount_in = strtotime($vpn->vpn_date_lastcall_in);
            $callcount_out = strtotime($vpn->vpn_date_lastcall_out);

            if ($vpn->status === 1) {
                 $lastStatus = min($callcount_in, $callcount_out);
            }
            else {

                $intMinutes = 11;
                $intSeconds = $intMinutes * 60;
                $now = time();
                $chk = $now + $intSeconds;

                // If the date_callcount_out is 11 minutes overdue and the date_callcount_in is not 11 minutes over due,
                // then the last check would be on the last 10 minute interval.
                // For example, if it is currently 8:37 am and date_callout_out is over 11 minutes ago, the last status should be at 8:30 am.
                if(($callcount_in < $chk) && ($callcount_out > $chk)){
                    $lastStatus = $now - $now % (60*10);
                }

                // If the date_callcount_in is 11 minutes overdue and the date_callcount_out is not 11 minutes over due,
                // then the last check would be on the last 4 minute interval.
                // For example, if it is currently 8:34 am and date_callout_in is over 11 minutes ago, the last status should be at 8:32 am.
                else if(($callcount_in > $chk) && ($callcount_out < $chk)){
                    $lastStatus = $now - $now % (60*4);
                }

                // If both the date_callcount_in and the date_callout_out are 11 minutes overdue, the last status should be the greater of the 2.
                // For example, if both are overdue and it is 8:51am, the last status should be 8:48am.
                else if(($callcount_in < $chk) && ($callcount_out < $chk)){
                    $interval = $callcount_in < $callcount_out ? 4 : 10;
                    $lastStatus = $now - $now % (60*$interval);
                }

                else {
                    return false;
                }

            }

            $date = new DateTime('@'.$lastStatus);
            $date->setTimezone(new DateTimeZone('America/Vancouver'));
            $timestamp = $date->format('Y-m-d H:i:0 T');

            date_default_timezone_set($user->timezone);
        }

        return !empty($timestamp) ? $timestamp : false;
    }

    /**
     * Generate a random ID for the new VPN
     * This cannot be a pre existing VPN id
     * @return    string        
     */
    public static function generateId() {
        $vpns = DB::select(Functions::prefix('SELECT id FROM {alchemy_identity}.vpn WHERE status = 1'), array(), 'vpn.get_live');
        if (empty($vpns)) {
            return false;
        }

        do {
            $id = rand(1000, 9999);
            $match = false;
            foreach ($vpns as $vpn) {
                if ((int) $id === (int) substr($vpn->id, 0, 4)) {
                    $match = true;
                    break;
                }
            }
        } while ($match);

        return ((string) $id) . 0;
    }

    /**
     * Get a single VPN
     * @param     integer    $id    The id of the VPN we want
     * @return                      Database collection object
     */
    public static function getVpn($id) {
        $query = 'SELECT v.*, vi.*,
                         v.status as vpn_active,
                         v.id as id,
                         vl.ip_address,
                         vl.city as lead_city,
                         vl.state as lead_state,
                         vl.isp as lead_isp,
                         vl.payment_method as payment_method,
                         vl.payment_key as payment_key,
                         vl.payment_amount,
                         vl.notify_vpn_down as notify_vpn_down,
                         vi.pi_id,
                         vi.psu_id,
                         vi.sd_id,
                         vi.cable_id,
                         IF(v.date_shipped IS NULL, 0, ## BLANK if not shipped
                            IF (v.state = "Ready",
                                CASE
                                    WHEN (COALESCE(v.date_lastcall_in, 0) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) THEN 0
                                    WHEN (COALESCE(v.date_lastcall_out, 0) < DATE_SUB(NOW(), INTERVAL 10 MINUTE)) THEN 0
                                    ELSE 1
                                END,
                            0)
                        ) as vpn_status
                    FROM {alchemy_identity}.vpn v
                    LEFT JOIN {alchemy_identity}.vpn_info vi ON v.id = vi.vpn_id
                    LEFT JOIN {alchemy_identity}.vpn_lead vl ON vi.vpn_lead_id = vl.id
                    WHERE v.id = ?';
        $result = DB::select(Functions::prefix($query), array($id), 'vpn.get');
        return !empty($result) ? $result[0] : false;
    }

    /**
     * Get the various states of the VPN in question
     * @param     object     $user         The user object of the currently logged in user
     * @param     boolean    $forSelect    Is this for an HTML select tag or raw data
     * @param     boolean    $override     Override security layers. Needed for CLI calls that act as super admin
     * @return    array                    Multidimensional array of all the states the VPN is reporting
     */
    public static function getStates($user, $forSelect = true, $override = false) {
        $states[] = array(
            'name' => self::STATE_RESETTING,
            'class' => 'badge-warning',
            'prepend' => '<i class="fa fa-exclamation-circle"></i>',
            'selectable' => false,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_ACTIVATING,
            'class' => 'alert-info',
            'prepend' => '<i class="fa fa-minus"></i>',
            'selectable' => false,
            'access' => array('vpn_user'),
        );
        $states[] = array(
            'name' => self::STATE_ACTIVATED_COMPONENTS,
            'class' => 'alert-warning',
            'prepend' => '<i class="fa fa-exclamation-circle"></i>',
            'selectable' => false,
            'access' => array('vpn_user'),
        );
        $states[] = array(
            'name' => self::STATE_DOWN_MAJOR,
            'class' => 'alert-danger',
            'prepend' => '<i class="fa fa-times"></i>',
            'selectable' => false,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_DOWN_MINOR,
            'class' => 'alert-warning',
            'prepend' => '<i class="fa fa-exclamation-circle"></i>',
            'selectable' => false,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_VPN_ISSUES,
            'class' => 'alert-warning',
            'prepend' => '<i class="fa fa-exclamation-circle"></i>',
            'selectable' => false,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_TECH_SUPPORT,
            'class' => 'alert-warning',
            'prepend' => '<i class="fa fa-gear"></i>',
            'selectable' => true,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_IP_CHANGED,
            'class' => 'alert-warning',
            'prepend' => '<i class="fa fa-exclamation-circle"></i>',
            'selectable' => false,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_ACTIVATED,
            'class' => 'alert-info',
            'prepend' => '<i class="fa fa-minus"></i>',
            'selectable' => true,
            'access' => array('vpn_user'),
        );
        $states[] = array(
            'name' => self::STATE_SHIPPED,
            'class' => 'alert-info',
            'prepend' => '<i class="fa fa-minus"></i>',
            'selectable' => true,
            'access' => array('vpn_user'),
        );
        $states[] = array(
            'name' => self::STATE_RETURNING,
            'class' => 'alert-info',
            'prepend' => '<i class="fa fa-minus"></i>',
            'selectable' => true,
            'access' => array('vpn_user'),
        );
        $states[] = array(
            'name' => self::STATE_UNREACHABLE,
            'class' => 'alert-danger',
            'prepend' => '<i class="fa fa-times"></i>',
            'selectable' => true,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_KILLING,
            'class' => 'alert-danger',
            'prepend' => '<i class="fa fa-times"></i>',
            'selectable' => true,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_READY,
            'class' => 'alert-success',
            'prepend' => '<span class="badge badge-success">&nbsp;</span>',
            'selectable' => true,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_RETURNED,
            'class' => '',
            'prepend' => '',
            'selectable' => true,
            'access' => array('vpn_user'),
        );
        $states[] = array(
            'name' => self::STATE_REUSE,
            'class' => '',
            'prepend' => '',
            'selectable' => true,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_COLLECTIONS,
            'class' => '',
            'prepend' => '',
            'selectable' => true,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_LOST,
            'class' => '',
            'prepend' => '',
            'selectable' => true,
            'access' => array(),
        );
        $states[] = array(
            'name' => self::STATE_NOT_READY,
            'class' => '',
            'prepend' => '',
            'selectable' => false,
            'access' => array(),
        );

        foreach ($states as $id => $state) {
            if ($forSelect && !$state['selectable']) {
                unset($states[$id]);
            }
            if ($user && !$user->hasAnyAccess(array('admin', 'symbiosis_vpn_manager')) && !$override) {
                if (empty($state['access']) || !$user->hasAnyAccess($state['access'])) {
                    $states[$id] = false;
                }
            }
        }

        return $states;

    }

    public static function getStateSelectValues($user, $currentState = null) {
        $states = self::getStates($user);
        $values = array();
        if ($currentState) {
            $values[$currentState] = $currentState;
        }
        foreach ($states as $state) {
            $values[$state['name']] = $state['name'];
        }
        asort($values);
        return $values;
    }

    public static function getStateIdByName($user, $stateName) {
        $states = self::getStates($user, false, true);
        foreach ($states as $id => $state) {
            if (!$state) continue;
            if ($state['name'] === $stateName) {
                return $id;
            }
        }

        return -1;
    }

    public static function getPaidTimelineOptions() {
        return array(
            self::PAID_TIMELINE_DAILY => 'Daily',
            self::PAID_TIMELINE_WEEKLY => 'Weekly',
            self::PAID_TIMELINE_MONTHLY => 'Monthly',
        );
    }

    public static function getLoadMethods() {
        return array(
            self::LOAD_METHOD_MANUAL => 'Manual',
            self::LOAD_METHOD_RPI_CLONE => 'RPI Clone',
            self::LOAD_METHOD_IMAGE => 'Image',
            self::LOAD_METHOD_THEPIHUT => 'The Pi Hut',
            self::LOAD_METHOD_PISUPPLY => 'Pi Supply',
        );
    }

    public static function getSDCards() {
        return array(
            self::SD_CARD_SAMSUNG => 'Samsung',
            self::SD_CARD_KINGSTON => 'Kingston',
            self::SD_CARD_SANDISK => 'Sandisk',
            self::SD_CARD_SANDISK_HCI => 'Sandisk-HCI',
            self::SD_CARD_MICRO_SAMSUNG => 'Micro SD Samsung',
        );
    }

    public static function getPSUs() {
        return array(
            self::PSU_ORIGINAL => 'Original',
            self::PSU_DETACHED => 'Detached',
            self::PSU_THEPIHUT => 'The Pi Hut',
            self::PSU_PISUPPLY => 'Pi Supply',
        );
    }

    public static function getPaymentMethods() {
        return array(
            self::PAYMENT_METHOD_PAYPAL => 'Paypal',
            self::PAYMENT_METHOD_ZOOMBUCKS => 'Zoombucks',
            self::PAYMENT_METHOD_HERMAN => 'Herman',
            self::PAYMENT_METHOD_UNKNOWN => 'Unknown',
            self::PAYMENT_METHOD_AMAZON => 'Amazon Gift Card',
        );
    }

    public static function getShippingMethods() {
        return array(
            self::SHIPPING_METHOD_USPS => 'USPS',
            self::SHIPPING_METHOD_CANADAPOST => 'Canada Post',
        );
    }

    public static function getIdsFromPattern($id) {
        $query = "SELECT id
                  FROM {alchemy_identity}.vpn
                  WHERE id LIKE ?
                  ORDER BY id ASC";
        return DB::select(Functions::prefix($query), array($id.'_'), 'vpn.like');
    }

    /**
     * Get the approximate uptime percent of the VPN
     * @return    decimal    The decimal value representing the uptime percent
     */
    public static function getUptimePercent() {
        $query = "  SELECT  COUNT(*) AS total,
                            SUM(v.uptime_lastday) AS uptime
                    FROM    {alchemy_identity}.vpn v
                        join {alchemy_identity}.identity2 i ON v.id = i.vpn_id
                        join account a ON i.account_id = a.id
                        join account_story acs ON a.id = acs.account_id
                        join story s ON acs.story_id = s.id
                    WHERE v.state != 0
                        AND v.state = 'Ready'
                        AND i.status = 'ready'
                        AND a.suspended_at IS NULL
                        AND s.reclaimed_at IS NULL";
        $result = DB::select(Functions::prefix($query));
        if (empty($result)) {
            return 0;
        }

        return $result[0]->total ? round($result[0]->uptime / $result[0]->total, 2) : 0;
    }

    /**
     * Styling colors for VPN states based on their uptime
     * @param     decimal    $val    Decimal value of VPN uptime percent
     * @return    array              Array of color, badge type and icon
     */
    public static function getUptimeColors($val){
        if (!empty($val)){
            if ($val >= 0.9){
                $color = 'alert-success';
                $badge = 'badge-success';
                $marker = 'fa-check';
            } else {
                $color = 'alert-danger';
                $badge = 'badge-important';
                $marker = 'fa-times';
            }
        } else {
            $color = '';
            $badge = '';
            $marker = '';
        }
        return array('color' => $color, 'badge' => $badge, 'marker' => $marker);
    }

    public static function getChatSetting() {
        $sql = "SELECT * FROM {netspeed_panel}.setting LIMIT 1";
        return DB::select(Functions::prefix($sql));
    }

    public static function updateIP($id) {
        $query = 'UPDATE {alchemy_identity}.vpn as v
                    LEFT JOIN {alchemy_identity}.vpn_info AS vi ON vi.vpn_id = v.id
                    LEFT JOIN {alchemy_identity}.vpn_lead vl ON vi.vpn_lead_id = vl.id
                    SET v.date_ip_changed = now(),
                        v.ip_activated = ip_lastcall,
                        vl.ip_address = ip_lastcall
                    WHERE v.id= ?';
        return DB::update(Functions::prefix($query), array($id), 'vpn.update_ip');
    }

    public static function updatePassword($id, $password) {
        return DB::table(Functions::prefix('{alchemy_identity}.vpn'))->where('id', $id)->update(array('password' => $password));
    }

    public static function updateChatSetting($id, $value) {
        $result = DB::update(Functions::prefix("UPDATE {netspeed_panel}.setting SET value = ? WHERE id = ?"), array($value, (int)$id));
        return $result;
    }

    private static function updateVPNIds($newId, $oldId) {
        $query = "UPDATE {alchemy_identity}.vpn v
                    JOIN {alchemy_identity}.identity2 i2
                        ON v.id = i2.vpn_id
                    JOIN {alchemy_identity}.vpn_info vi
                        ON v.id = vi.vpn_id
                  SET v.id = ?,
                      v.status = 0,
                      i2.vpn_id = ?,
                      vi.vpn_id = ?
                  WHERE v.id = ?";

        $updated = DB::update(Functions::prefix($query), array($newId, $newId, $newId, $oldId), 'vpn.update_ids');
        return $updated > 0 ? true : false;
    }

    public static function insertVpnLead($values, $user = null){
        if ($user && !$user->hasAccess('admin')) {
            return false;
        }

        $lead_id = DB::table(Functions::prefix('{alchemy_identity}.vpn_leads'))->insertGetId($values);

        return !empty($lead_id) ? $lead_id : false;
    }

    public static function insertVPN($oldId, $password) {
        $query = "INSERT INTO {alchemy_identity}.vpn (id, status, state, password)
                  VALUES (?, 1, ?, ?)";

        $inserted = DB::insert(Functions::prefix($query), array($oldId, self::STATE_READY, $password), 'vpn.insert');
        return $inserted > 0 ? true : false;
    }

    public static function insertVPNInfo($oldId) {
        $query = "INSERT INTO {alchemy_identity}.vpn_info (vpn_id, cost)
                  VALUES (?, 0)";

        $inserted = DB::insert(Functions::prefix($query), array($oldId), 'vpn.insert_info');
        return $inserted > 0 ? true : false;
    }

    public static function getLossPercent($datetime) {
        $query = "  SELECT  COUNT(*) AS total,
                            SUM(IF(state IN ('Lost', 'Collections'), 1, 0)) AS lost
                    FROM    {alchemy_identity}.vpn
                    WHERE   status = 1
                        AND date_shipped IS NOT NULL";
        $result = DB::select(Functions::prefix($query));
        if (empty($result)) {
            return 0;
        }

        return $result[0]->total ? round($result[0]->lost / $result[0]->total, 2) : 0;
    }

    public static function logState($id, $state, $prevState = null) {
        if ($state === $prevState) {
            return false;
        }

        return DB::table(Functions::prefix('{alchemy_identity}.vpn_state_log'))->insert(array(
            'vpn_id' => $id,
            'state' => $state,
            'prev_state' => $prevState,
            'created_at' => time(),
        ));
    }

    public static function restart($id) {
        return DB::update(Functions::prefix('UPDATE {alchemy_identity}.vpn SET command_bit = 1 WHERE id = ?'), array($id), 'vpn.restart');
    }

    public static function repurpose($newId, $oldId, $vpn) {
        self::updateVPNIds($newId, $oldId);
        self::insertVPN($oldId, $vpn->password);
        self::insertVPNInfo($oldId);
    }

    public static function updateVpnInfo($id, $params) {
        return DB::table(Functions::prefix('{alchemy_identity}.vpn_info'))->where('vpn_id', $id)->update($params);
    }

    public static function updatePaidDate($paidUntilDate, $id) {
        $query = "UPDATE {alchemy_identity}.vpn_info
                    SET paid_until = ?
                    WHERE vpn_id = ?";

        $updated = DB::update(Functions::prefix($query), array($paidUntilDate, $id), 'vpn.update_paid');
        return $updated > 0 ? true : false;
    }

}
