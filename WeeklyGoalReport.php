<?php

namespace Stanford\WeeklyGoalReport;
/** @var \Stanford\WeeklyGoalReport\WeeklyGoalReport $module */

use \REDCap as REDCap;
use \DateTime as DateTime;


class WeeklyGoalReport extends \ExternalModules\AbstractExternalModule {


    /**
     * There is a separate project that surveys absent week.
     * Dates can overlap so make sure there is just ne entry
     *
     * @param $pid
     * @return array
     */
    function getVacationData($pid) {
        $vacation_fields = array('user_id',
            'start_date_1', 'end_date_1', 'start_date_2', 'end_date_2', 'start_date_3', 'end_date_3',
            'ill_start_date_1', 'ill_end_date_1', 'ill_start_date_2', 'ill_end_date_2', 'ill_start_date_3', 'ill_end_date_3',
            'future_start_1', 'future_end_1', 'future_start_2', 'future_end_2', 'future_start_3', 'future_end_3');
        $v = REDCap::getData($pid, 'json',null, $vacation_fields);
        $vacation_data = json_decode($v, true);

        //separate out vacation / illness / future vacation into separate arrays
        $exclude_days = array();
        foreach ($vacation_data as $key => $val) {

            $exclude_days[$val['user_id']]['vacation'][$val['start_date_1']] = $val['end_date_1'];
            $exclude_days[$val['user_id']]['vacation'][$val['start_date_2']] = $val['end_date_2'];
            $exclude_days[$val['user_id']]['vacation'][$val['start_date_3']] = $val['end_date_3'];

            $exclude_days[$val['user_id']]['illness'][$val['ill_start_date_1']] = $val['ill_end_date_1'];
            $exclude_days[$val['user_id']]['illness'][$val['ill_start_date_2']] = $val['ill_end_date_2'];
            $exclude_days[$val['user_id']]['illness'][$val['ill_start_date_3']] = $val['ill_end_date_3'];

            $exclude_days[$val['user_id']]['future'][$val['future_start_1']] = $val['future_end_1'];
            $exclude_days[$val['user_id']]['future'][$val['future_start_2']] = $val['future_end_2'];
            $exclude_days[$val['user_id']]['future'][$val['future_start_3']] = $val['future_end_3'];
        }

        return $exclude_days;

    }

    function getExcludedWeeks($excluded_days, $vacation, $illness, $future_vacation) {
        //convert this to exclude_weeks. list as weeks (like the Participant Data
        $exclude_week = array();

        foreach ($excluded_days as $id => $type ) {

            $prefix = $this->getProjectSetting('survey_fk_prefix');

            if (is_numeric($id)) {
                $id = $prefix . str_pad($id, 4, '0', STR_PAD_LEFT);
            }

            //$this->emDebug($type, "TYPE for $id");

            //if vacation is turned on, go through vacation in the arrays and add to list
            if ($vacation) {
                $exclude_week = $this->updateExcludedWeeks($exclude_week, $id, $type, 'vacation');
            }
            if ($illness) {
                $exclude_week = $this->updateExcludedWeeks($exclude_week, $id, $type, 'illness');
            }
            if ($future_vacation) {
                $exclude_week = $this->updateExcludedWeeks($exclude_week, $id, $type, 'future');
            }

        }

        return $exclude_week;

    }

    function updateExcludedWeeks($exclude_week, $id, $type, $exclude = 'vacation') {

        //cut off future dates here
        $today = new DateTime();

        foreach ($type[$exclude] as $start => $end) {
            if ($start == null) continue;

            $start_date = DateTime::createFromFormat('Y-m-d', $start);
            $end_date = DateTime::createFromFormat('Y-m-d', $end);

            if ($start_date > $today) {
                //$this->emDebug($start_date . " is greater than today");
                continue;
            }
            if ($end_date > $today) {
                //$this->emDebug($end_date . " is greater than today. use today");
                $end_date = $today;
            }

            $start_yearweek = $this->getYearWeekShiftedSunday($start_date);
            $end_yearweek = $this->getYearWeekShiftedSunday($end_date);

            for ($j = $start_yearweek; $j <= $end_yearweek; $j++){
                $exclude_week[$id][$j] = $end_date->format('Y-m-d');
            }
        }

        return $exclude_week;
    }


    /**
     * Return date in YYYYWW format
     * If date is Sunday push forward 1 week since default is M->Su and we want Su->Sa
     *
     * @param $date
     * @return string
     */
    function getYearWeekShiftedSunday($date) {

        $date_year = $date->format('Y');
        $date_week = $date->format('W');

        //if sunday push week forward by one since default is M->Su and we want Su->Sa
        if ($date->format('w') == 0) {

            if (date('W', strtotime($date->format('Y') . "-12-31")) == 52 and $date->format('W') == 52) {
                $date_week = 1;
                $date_year++;
            } elseif (date('W', strtotime($date->format('Y') . "-12-31")) == 53 and $date->format('W') == 53) {
                $date_week = 1;
                $date_year++;
            } else {
                $date_week++;
            }
        }

        //pad the week with 0
        $date_week_padded = str_pad($date_week, 2, '0', STR_PAD_LEFT);
        return $date_year.$date_week_padded;

    }


    function countAbsentWeeks($array1) {

        $array = array_map(function($element){
            return $element['absent'];
        }, $array1);

        $array2 = (array_count_values($array));

        return $array2[1];
        //return $array2;
    }


    function sumCappedCount($array1) {

        $array = array_map(function($element){
            return $element['capped_count'];
        }, $array1);

        $array2 = (array_sum($array));

        //return $array2[1];
        return $array2;
    }

    function getParticipantData($id=null, $cfg, $withdrawn) {
        global $module;

        // In the event the survey project is longitudinal, we need to use the event ID
        $survey_event_id = empty($cfg['PARTICIPANT_EVENT_ARM_NAME']) ? NULL : StaticUtils::getEventIdFromName($cfg['SURVEY_PID'], $cfg['PARTICIPANT_EVENT_ARM_NAME']);
        $survey_event_prefix = empty($cfg['PARTICIPANT_EVENT_ARM_NAME']) ? "" : "[" . $cfg['PARTICIPANT_EVENT_ARM_NAME'] . "]";

        if ($id == null) {
            $filter = null; //get all ids
        } else {
            $filter = $survey_event_prefix. "[{$cfg['SURVEY_FK_FIELD']}]='$id'";

        }

        //if withdrawn checkbox is set, filter out withdrawn participants
        if ($withdrawn) {
            //
            if ($id != null) {
                $filter .= " AND ";
            }
            $filter .= $survey_event_prefix. "[{$cfg['WITHDRAWN_STATUS_FIELD']}(1)]<>'1'";

            //$module->emDebug($filter, 'FILTER SINCE WITHDRAWN');
        }


        $get_data = array(
            $cfg['SURVEY_PK_FIELD'],
            $cfg['SURVEY_FK_FIELD'],
            $cfg['START_DATE_FIELD'],
            $cfg['END_DATE_FIELD'],
            $cfg['WITHDRAWN_STATUS_FIELD'],
            $cfg['GROUP_FIELD'],
            $cfg['SURVEY_FORM_NAME'] . '_complete'
        ) ;

        $q = REDCap::getData(
            $cfg['SURVEY_PID'],
            'json',
            NULL,
            $get_data,
            $survey_event_id,
            NULL,FALSE,FALSE,FALSE,
            $filter
        );

        $results = json_decode($q,true);
        //Plugin::log($results, "DEBUG", "RESULTS");
        return $results;
    }

    static function getMultiplierForGroup($group) {
        switch ($group) {
            case '1':
                $multiplier = 1;
                break;
            case '2':
                $multiplier = 3;
                break;
            default:
                $multiplier = 3; //make default 3 since Strong  multiplier is 3 and no multiplier group
        }

        return $multiplier;
    }

    /**
     * Final summary table of all the counts (raw and capped) and absences by year_week
     * Only includes data from start date to end date
     *
     * @param $survey_data
     * @param $participant_data
     * @param $cfg
     * @return array
     * @throws \Exception
     */
     function calcCappedCount($survey_data, $participant_data, $cfg) {
        //table with columns : participant, year, weeknumber, count, capped count, randomization group?
        $capped  = array();

        //get today
        $today = new \DateTime();
//        $year =  self::getYear($today);
//        $current_week =  self::getWeek($today);


        //iterate over survey_data and calculate capped count using $participant randomization group
        foreach ($survey_data as $participant => $v) {

            //get cap from $participant_data
            $cap = self::getMultiplierForGroup($participant_data[$participant][$cfg['GROUP_FIELD']]);

            //get the start and end for this participant
            $start_str = $participant_data[$participant][$cfg['START_DATE_FIELD']];
            $end_str   = $participant_data[$participant][$cfg['END_DATE_FIELD']];

            //if start is blank then bail
            if ($start_str == '') {
//                $this->emDebug("start is blank ".$start_str);
                $this->emError("Start Date is missing for this participant: ".$participant);
                continue;
            }

            $start    = new DateTime($start_str);
            $end      = new DateTime($end_str);

            //if end is after today, use today
            if ($end > $today) {
                $end = $today;
            }

            //create interval by week, starting from the Sunday of the $start date
            $start_sunday = $start;
//            $this->emDebug("*****START IS ". $start->format('Y-m-d') . " WEEK : ". $start->format(YW) .
//                " DAY OF WEEK : " . $start->format('l')  . " DAY OF WEEK : " . $start->format('w'));

            //if start is not on sunday, shift it back to sunday
            if ($start->format('w') != 0) {
                $start_sunday = $start_sunday->modify('last Sunday');
//                $this->emDebug("START_SUNAY IS ". $start_sunday->format('Y-m-d') ." WEEK : ".  $start_sunday->format(YW) .
//                    " DAY OF WEEK IS : " . $start_sunday->format('l') . " DAY OF WEEK : " . $start_sunday->format('w'));
            }

            $interval = new \DateInterval('P1W');
            $period   = new \DatePeriod($start_sunday, $interval, $end);

            //iterate over each week from start to end for this participant
            foreach ($period as $date) {

                $c_yrweek = $this->getYearWeekShiftedSunday($date);

                $raw_count = count($v[$c_yrweek]);
                $capped_count = ($raw_count > $cap) ? $cap : $raw_count ;

                $capped[$participant][$c_yrweek]['raw_count'] = $raw_count;
                $capped[$participant][$c_yrweek]['capped_count'] = $capped_count;
                $capped[$participant][$c_yrweek]['adhered'] = ($raw_count) >= $cap ? 1: 0;
            }


        }

        return $capped;
    }

    /**
     * From Lida: if absent, then the week is automatically counted as adhered.
     *
     * @param $counts
     * @param $excluded_weeks
     * @return mixed
     */
    function countWithAbsence($counts, $excluded_weeks) {
        $amended_counts = $counts;
        foreach ($counts as $participant => $count) {
            foreach($count as $yearweek => $absences ) {
                $absent =  isset($excluded_weeks[$participant][$yearweek]) ? 1 : 0;
                $amended_counts[$participant][$yearweek]['absent'] = $absent;

                //if they were absent then it gets counted as adhered.
                $amended_counts[$participant][$yearweek]['absent_adhered'] =
                    ($amended_counts[$participant][$yearweek]['adhered'] + $absent) > 0 ? 1 : 0;
                //$this->emDebug("PARTICIPANT is $participant and year week is $yearweek");
            }
        }
        //$this->emDebug($amended_counts); exit;

        return $amended_counts;
    }


    /**
     * Lookup the decode of the coordinator dropdown from the data dictionary and return
     * list of coordinators by pariticpant id
     *
     * @return array
     * @throws \Exception
     */
    function getCoordinatorDecoded() {
        $coord_field       = $this->getProjectSetting('coordinator_field');
        //$coord_field_event = $this->getProjectSetting('coordinator_field_event');
        $coord_field_pid   = $this->getProjectSetting('main_pid');
        //$coord_field_event_id = empty($coord_field_event) ? NULL : $this->getEventIdFromName($coord_field_pid,$coord_field_event);

        $params = array(
            'project_id'    => $coord_field_pid,
            'fields'        => array($coord_field),
            'events'        => $coord_field_event
            //'return_format' => 'json'
        );

        $results = REDCap::getData($params);
        //$results  = json_decode($q, true);

        //get the decode for the coordinator field
        $dict = REDCap::getDataDictionary($coord_field_pid, 'array', false, $coord_field);

        $coord_string = $dict[$coord_field]['select_choices_or_calculations'];
        $coord_exploded = explode('|',$coord_string);
        foreach ($coord_exploded as $k => $v) {
            $e = explode(',', $v);

            $coord_decode[trim($e[0])] =$e[1];
        }

        $decoded = array();

        //$re = '/^(32113-)?(?\'id\'\w*)/m';
        $re = '/^(?\'grp\'32113-|2151-)?(?\'id\'\w*)/m';

        foreach ($results as $id => $event) {
            //STRONGD is prefixed, but IMPACT is not.
            //removed the prefix for id ('32113-')
            preg_match_all($re, $id, $matches, PREG_SET_ORDER, 0);

            //it turns out that IMPACT uses the whole ID and STRONGD cuts off prefix
            if (isset($matches[0]['grp'])  &&  ($matches[0]['grp'] == '2151-')) {
                //it's IMPACT so use the whole ID
                $fixed_id = $id;
            } else {
                //its STRONGD so strip off the prefix

                $fixed_id = $matches[0]['id'];
                $this->emDebug($fixed_id, $id);
                //$decoded[$stripped_id] = $coord_decode[$event[$coord_field_event_id][$coord_field]];
            }
            $decoded[$fixed_id] = $coord_decode[(current($event))[$coord_field]];
        }

        //$this->emDebug($decoded); exit;
        return $decoded;


    }

    /**
     * DELETE NOT USED
     * @param $project_id
     * @param $event_name
     * @return |null
     * @throws \Exception
     */
     static function getEventIdFromName($project_id, $event_name) {
        //Plugin::log($event_name, "DEBUG", "Getting event for project ".$project_id);

        if (empty($event_name)) return NULL;
        $thisProj = new \Project($project_id,false);
        $thisProj->loadEventsForms();
        $event_id_names = $thisProj->getUniqueEventNames();

        //Plugin::log($event_id_names, "DEBUG", "Getting event id names for project ".$project_id);

        $event_names_id = array_flip($event_id_names);
        return isset($event_names_id[$event_name]) ? $event_names_id[$event_name] : NULL;
    }

    /**
     * Returns all surveys for a given record id
     *
     * @param $id  participant_id (if null, return all)
     * @param $cfg
     * @return mixed
     */
    static function getAllSurveys($id = null, $cfg) {
        // In the event the survey project is longitudinal, we need to use the event ID
        $survey_event_id = empty($cfg['SURVEY_EVENT_ARM_NAME']) ? NULL : StaticUtils::getEventIdFromName($cfg['SURVEY_PID'], $cfg['SURVEY_EVENT_ARM_NAME']);
        $survey_event_prefix = empty($cfg['SURVEY_EVENT_ARM_NAME']) ? "" : "[" . $cfg['SURVEY_EVENT_ARM_NAME'] . "]";

        if ($id == null) {
            $filter = null; //get all ids
        } else {
            $filter = $survey_event_prefix . "[{$cfg['SURVEY_FK_FIELD']}]='$id'";
        }

        $get_data = array(
            $cfg['SURVEY_PK_FIELD'],
            $cfg['SURVEY_FK_FIELD'],
            $cfg['SURVEY_TIMESTAMP_FIELD'],
            $cfg['SURVEY_DATE_FIELD'],
            $cfg['SURVEY_DAY_NUMBER_FIELD'],
            $cfg['SURVEY_FORM_NAME'] . '_complete'
        ) ;

        $q = REDCap::getData(
            $cfg['SURVEY_PID'],
            'json',
            NULL,
            $get_data,
            $survey_event_id,
            NULL,FALSE,FALSE,FALSE,
            $filter
        );

        $results = json_decode($q,true);
        return $results;
    }


    /**
     * Week goes from Sunday to Saturday
     *
     * Return all the survey date by [participant_id][year_week] as key
     *
     * @param $surveys
     * @param $key_field
     * @param $date_field
     * @return array
     */
    static function arrangeSurveyByIDWeek($surveys, $key_field, $date_field) {
        $r = array();
        foreach ($surveys as $d) {
            $date = new DateTime($d[$date_field]);

            //do this by yearweek

            $date_year = $date->format('Y');
            $date_week = $date->format('W');

            //default designation of week is Monday to Sunday, so If Sunday push to next week
            if ($date->format('w') == 0) {
                $date_week = $date_week + 1;
            }

            //$r[$d[$key_field]][self::getYear($date)][self::getWeek($date)][] = $d;
            $r[$d[$key_field]][$date_year.$date_week][] = $d;

            //increment count of surveys
            $r[$d[$key_field]]['count'] += 1;
        }
        return $r;
    }

    /**
     * @param $valid_day_number_array
     * @param $survey_data
     * @param $start_date
     * @return array
     */
    function getAttendedDayNumbers($survey_data, $start_date, $end_date) {
                                       //$start_field, $start_field_event, $valid_day_number_array) {
//        $start_date = StaticUtils::getFieldValue($pk, $project_id, $start_field, $start_field_event);
        //$this->emDebug($survey_data, "VALID DAY NUMBERS"); exit;
        $valid_days = array();


        //iterate over each day from start to enddate
        $interval = new \DateInterval('P1D');
        $period   = new \DatePeriod(new DateTime($start_date), $interval,new DateTime($end_date));

        foreach ($period as $dt) {

            $date = $dt->format('Y-m-d');

            if ( isset($survey_data[$date]) ) {
                $c_yrweek = $this->getYearWeekShiftedSunday($dt);
                $valid_days[$date]['STATUS'] = isset($survey_data[$date]) ? "1" : "-1";
                $valid_days[$date]['DAY_NUMBER'] = $c_yrweek;
            }

        }
        //$this->emDebug($valid_days, "VALID DAY NUMBERS 2"); exit;

        return $valid_days;
    }

    /**
     * Gets the date based on a start_date and days offset
     *
     * @param $start_date
     * @param $day
     * @param string $format
     * @return string
     */
    function getDateFromDayNumber($start_date, $day, $format = "Y-m-d") {
        $this_dt = new \DateTime($start_date);
        $this_dt->modify("+$day day");
        return $this_dt->format($format);
    }

    /**
     * @param $surveys
     * @param $portal_data
     * @param $portal_start_date_field
     * @param $survey_pk_field
     * @param $survey_fk_field
     * @param $survey_date_field
     * @param $survey_day_number_field
     * @param $survey_form_name_complete
     * @return array
     */
    static function arrangeSurveyByID($surveys, $portal_data, $portal_start_date_field,
                                      $survey_pk_field, $survey_fk_field, $survey_date_field,
                                      $survey_day_number_field, $survey_form_name_complete) {
        $arranged = array();

        foreach ($surveys as $c) {
            $id = $c[$survey_fk_field];
            $survey_date = $c[$survey_date_field];

            $arranged[$id][$survey_date] = array(
                "START_DATE"   => $portal_data[$id][$portal_start_date_field],
                "RECORD_NAME"  => $c[$survey_pk_field],
                "DAY_NUMBER"   => $c[$survey_day_number_field],
                "STATUS"       => $c[$survey_form_name_complete]
            );
        }

        return $arranged;

    }

    /**
     * @param $survey_fk_field
     * @param $all_surveys
     * @return array
     */
    static function getUniqueParticipants($survey_fk_field, $all_surveys) {
        //return unique single level array
        $pids = array();
        foreach ($all_surveys as $h) {
            $pids[] = $h[$survey_fk_field];
        }
        $unique_pids = array_unique($pids);

        return $unique_pids;

    }


    function startBootstrapPage($title, $header = '') {

        $html=<<<EOD
<!DOCTYPE html>
    <html>
        <head>
            <title>$title</title>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <meta name='apple-mobile-web-app-title' content='$title'>
            <link rel='apple-touch-icon' href='favicon/apple-touch-icon-iphone-60x60.png'>
            <link rel='apple-touch-icon' sizes='60x60' href='favicon/apple-touch-icon-ipad-76x76.png'>
            <link rel='apple-touch-icon' sizes='114x114' href='favicon/apple-touch-icon-iphone-retina-120x120.png'>
            <link rel='apple-touch-icon' sizes='144x144' href='favicon/apple-touch-icon-ipad-retina-152x152.png'>

            <!-- Bootstrap core CSS -->
            <link href='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css' rel='stylesheet' media='screen'>
            <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
            <!--[if lt IE 9]>
                <script src='https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js'></script>
                <script src='https://oss.maxcdn.com/respond/1.4.2/respond.min.js'></script>
            <![endif]-->
            <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
            <script src='https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js'></script>
            $header
        </head>
        <body>
EOD;
        return $html;
    }

    function endBootstrapPage($footer = "") {
        $html=<<<EOD
            <!-- Include all compiled plugins (below), or include individual files as needed -->
            <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js'></script>
            $footer
        </body>
    </html>
EOD;
        return $html;
    }

# When there is an error - display a nice message to end-user in bootstrap format and exit
    function exitMessage($msg, $title="", $include_bookmark_help = false) {

        $body = "
            <div class='container'>
                <div class='jumbotron text-center'>
                    <p>$msg</p>
                </div>
            </div>";
        if ($include_bookmark_help) $body .= $this->getBookmarkHelp();

        print $this->startBootstrapPage($title) . $body . $this->endBootstrapPage();
        exit();
    }

    public function getDateDiffWeeks($startDate, $endDate) {
        //if($startDate > $endDate) return getDateDiffWeeks($endDate, $startDate);
        $interval = $startDate->diff($endDate);
        $diff = floor(($interval->days) / 7);

        $today = new DateTime();

        if ($startDate > $today) {
            //$this->emDebug($startDate, "NOT YET STARTED RETURN NULL");
            return null;
        }

        //if sunday, add 1
        $day_of_week = $startDate->format('w');

        //if starts on a sunday, add 1
        if ($day_of_week=='0') {
            $diff = $diff + 1;
        }

        //$this->emDebug($diff, $startDate, "START DATE");
        //if it hasn't been a week yet, call it 1
        if ($diff == 0) {
            $diff =  1;
        }
        return $diff;
    }



    public function dumpResource($name) {
        $file =  $this->getModulePath() . $name;
        if (file_exists($file)) {
            $contents = file_get_contents($file);
            echo $contents;
        } else {
            $this->emError("Unable to find $file");
        }
    }

    /**
     *
     * emLogging integration
     *
     */
    function emLog() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "INFO");
    }

    function emDebug() {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || $this->getProjectSetting('enable-project-debug-logging')) {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            $emLogger->emLog($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function emError() {
        $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "ERROR");
    }


}