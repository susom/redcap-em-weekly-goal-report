<?php

namespace Stanford\WeeklyGoalReport;
/** @var \Stanford\WeeklyGoalReport\WeeklyGoalReport $module */

use \Plugin as Plugin;
use \REDCap as REDCap;
use \Project as Project;
use \DateTime as DateTime;
use \Survey as Survey;
use \LogicTester as LogicTester;


class WeeklyGoalReport extends \ExternalModules\AbstractExternalModule {


    public static function debug($obj) {
        // A really dumbed down logger for the template...
        error_log(json_encode($obj));
    }

    function getParticipantData($id=null, $cfg) {
        // In the event the survey project is longitudinal, we need to use the event ID
        $survey_event_id = empty($cfg['PARTICIPANT_EVENT_ARM_NAME']) ? NULL : StaticUtils::getEventIdFromName($cfg['SURVEY_PID'], $cfg['PARTICIPANT_EVENT_ARM_NAME']);
        $survey_event_prefix = empty($cfg['PARTICIPANT_EVENT_ARM_NAME']) ? "" : "[" . $cfg['PARTICIPANT_EVENT_ARM_NAME'] . "]";

        if ($id == null) {
            $filter = null; //get all ids
        } else {
            $filter = $survey_event_prefix . "[{$cfg['SURVEY_FK_FIELD']}]='$id'";
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
                $multiplier = 1;
        }

        return $multiplier;
    }

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
                continue;
            }

            $start    = new DateTime($start_str);
            $end      = new DateTime($end_str);

            //if end is after today, use today
            if ($end > $today) {
                $end = $today;
            }
            $interval = new \DateInterval('P1W');
            $period   = new \DatePeriod($start, $interval, $end);

            //iterate over each week from start to end for this participant
            foreach ($period as $date) {
                $c_yr = $date->format('Y');
                $c_week = $date->format('W');

                $raw_count = count($v[$c_yr][$c_week]);
                $capped_count = ($raw_count > $cap) ? $cap : $raw_count ;
//                $this->emDebug( $date->format('W') . " of ". $date->format('Y') . ' has count '.$raw_count);
                $capped[$participant][$c_yr."_".$c_week]['raw_count'] = $raw_count;
                $capped[$participant][$c_yr."_".$c_week]['capped_count'] = $capped_count;

            }
        }
//        $this->emDebug($capped);
        return $capped;
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
        //Plugin::log($results, "DEBUG", "RESULTS");
        return $results;
    }


    /**
     * TODO: check with Myo that week goes from Monday to Sunday (default)
     * @param $surveys
     * @param $key_field
     * @param $date_field
     * @return array
     */
    static function arrangeSurveyByIDWeek($surveys, $key_field, $date_field) {
        $r = array();
        foreach ($surveys as $d) {
            $date = $d[$date_field];

            $r[$d[$key_field]][self::getYear($date)][self::getWeek($date)][] = $d;

            //increment count of surveys
            $r[$d[$key_field]]['count'] += 1;
        }
        return $r;
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


    public static function getYear($pdate) {
        //$date = DateTime::createFromFormat("Y-m-d", $pdate);
        $foo = new \DateTime($pdate);

        return $foo->format("Y");
    }

    public static function getMonth($pdate) {
            $date = DateTime::createFromFormat("Y-m-d", $pdate);
            return $date->format("m");
        }

    public static function getWeek($pdate) {
        //$foo = DateTime::createFromFormat("Y-m-d", $pdate);
        $foo = new \DateTime($pdate);
        return $foo->format("W");
    }

    public static function getDay($pdate) {
        $date = DateTime::createFromFormat("Y-m-d", $pdate);
        return $date->format("d");
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