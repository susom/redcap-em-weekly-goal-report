<?php
namespace Stanford\WeeklyGoalReport;
/** @var \Stanford\WeeklyGoalReport\WeeklyGoalReport $module */

/**
 * From Myo Wong email : July 30, 2018
 * Overall Weekly Adherence:  We will need to break down the adherence for each week - we can use an if and then
 * statement. A few examples are provided in the attached excel sheet. For 1x/week, it should be 1 if (attended
 * sessions >=1). For 3x/week, it should be 3 if (attended sessions >=3). For this report, if they are going more
 * than their assigned weekly sessions, it would be capped at the max exercise session for their group.
 *
 * 5 week adherence: Will use the same logic/formula as the Overall weekly adherence, but will just look at
 * Current week and the last 4 weeks (current_week_# - 1, current_week_# - 2 until current_week_# - 4).
 */


use \DateTime as DateTime;

//include "common.php";
include_once("classes/StaticUtils.php");

//get the project settings defined in the config.json file
$cfg_orig  = $module->getProjectSettings($project_id);

//convert the $cfg into the version like the em
/**
 * (
[VERSION] => v9.9.9
[ENABLED] => 1
[ENABLE-PROJECT-DEBUG-LOGGING] => 1
[SURVEY_PID] => 107
[SURVEY_EVENT_ARM_NAME] => ctf_measurement_arm_2
[SURVEY_PK_FIELD] => participant_id
[SURVEY_FK_FIELD] => survey_participant_id
[SURVEY_TIMESTAMP_FIELD] => survey_datetime_created
[SURVEY_DATE_FIELD] => survey_date
[PARTICIPANT_EVENT_ARM_NAME] => participant_arm_1
[START_DATE_FIELD] => start_date
[END_DATE_FIELD] => end_date
[WITHDRAWN_STATUS_FIELD] => withdrawn
[GROUP_FIELD] => randomization_group
)
 */
$cfg = convertConfigToArray($cfg_orig);
//$module->emLog($cfg, "CONFIGURATION"); exit;

////////////////////////////////////////

//0. Set up the participant level data
$participant_data = WeeklyGoalReport::getParticipantData(null, $cfg);
//rearrange so that the id is the key
$participant_data = StaticUtils::makeFieldArrayKey($participant_data, $cfg['SURVEY_PK_FIELD']);
//$module->emDebug($participant_data, "PARTICIPANT DATA");

//1. Get all survey data from survey project
$surveys = WeeklyGoalReport::getAllSurveys(null, $cfg);
//$module->emDebug($surveys, "ALL SURVEYS");

//rearrange so that the id is the key
//todo: the default behaviour of the DateTime format("W") is to set week from Monday to Sunday
$survey_data = WeeklyGoalReport::arrangeSurveyByIDWeek($surveys, $cfg['SURVEY_FK_FIELD'], $cfg['SURVEY_DATE_FIELD']);
//$module->emDebug($survey_data, "ALL SURVEYS by ID by week" . $cfg['SURVEY_FK_FIELD']); exit;

//2. Get list of participants from surveys
//$participants = WeeklyGoalReport::getUniqueParticipants($cfg['SURVEY_FK_FIELD'], $surveys);
$participants = array_keys($participant_data);
//$module->emDebug($participants, "ALL PARTICIPANTS"); exit;

$counts= $module->calcCappedCount($survey_data, $participant_data, $cfg);
//$module->emDebug($counts, "ALL COUNTS"); exit;


//assemble table to display
$table_data = array();
$today = new DateTime();

foreach ($participants as $participant) {
    //reset values
    $total_week = '';
    $current_week = '';

    //get multiplier (dependant on the exercise group that the participant was randomized into
    $group = $participant_data[$participant][$cfg['GROUP_FIELD']];
    $multiplier = WeeklyGoalReport::getMultiplierForGroup($group);

    $start_date = $participant_data[$participant][$cfg['START_DATE_FIELD']];
    $end_date = $participant_data[$participant][$cfg['END_DATE_FIELD']];

    $begin = new \DateTime($start_date);

    //calculate the diff between start and end date (Total Weeks) only if end has been entered
    //$module->emDebug($end_date, 'end date');
    if ($end_date !=  null) {
        $end = new \DateTime($end_date);
        $total_week =  $module->getDateDiffWeeks($begin, $end);
    }

    //calculate the diff between start and today (Current Week)
    //only if the end date is past today
    if ($end > $today) {
        $current_week = $module->getDateDiffWeeks($begin, $today);
    }

    //todo: check with myo. Report total count of surveys as actual Attendance
    $actual_attendance = $survey_data[$participant]['count'];

    //total count over expected
    $percent_formatter = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
    $overall_adherence = $actual_attendance / ($total_week * $multiplier);
    $count_weeks = count($counts[$participant]);
    $count_attendance = array_sum(array_column($counts[$participant],'capped_count'));
    $weekly_adherence = $count_attendance / ($count_weeks * $multiplier);
    $five_wk_count = lastFiveAttended($counts[$participant]);
    $five_wk_adherence = $five_wk_count / (5 * $multiplier); //sometimes not 5 yet attendended

    //$module->emDebug($participant, "PARTICIPANT DATA".$cfg['WITHDRAWN_STATUS_FIELD'].'___1'.$cfg['GROUP_FIELD']);
    $table_data[$participant][$cfg['WITHDRAWN_STATUS_FIELD']] = $participant_data[$participant][$cfg['WITHDRAWN_STATUS_FIELD'].'___1'];
    $table_data[$participant][$cfg['GROUP_FIELD']]            = $group;
    $table_data[$participant][$cfg['START_DATE_FIELD']]       = $start_date;
    $table_data[$participant][$cfg['END_DATE_FIELD']]         = $end_date;
    $table_data[$participant]['current_week']                 = $current_week;
    $table_data[$participant]['total_weeks']                  = $total_week;
    $table_data[$participant]['expected_attendance']          = $total_week * $multiplier;
    $table_data[$participant]['actual_attendance']            = $actual_attendance;
    $table_data[$participant]['overall_adherence']            = $percent_formatter->format($overall_adherence);
    $table_data[$participant]['count']                        = $count_attendance;
    $table_data[$participant]['weekly_adherence']             =  $percent_formatter->format($weekly_adherence);
    $table_data[$participant]['five_week_count']              = $five_wk_count;
    $table_data[$participant]['five_week_adherence']          = $percent_formatter->format($five_wk_adherence);


}

//$module->emDebug($table_data, "ALL TABLE DATA");

$table_header = array("Participant","Withdraw Status","Arm","Start Date","End Date",
    "Current Week", "Total Weeks", "Expected Attendance", "Actual Attendance", "Overall Adherence",
    "counts", "Overall Weekly Adherence (count capped)", "5 week count", "5 week adherence" );


function lastFiveAttended($survey_data) {

    global $module;
    krsort($survey_data);

    $last_five = array_slice($survey_data, 0, 5, true);
    $count = array_sum(array_column($last_five,'capped_count'));
    //$module->emDebug($count, $last_five);
    return $count;
}

function convertConfigToArray($cfg) {
    $flattened = array();
    foreach ($cfg as $key => $val) {
        $flattened[strtoupper($key)] = $val['value'];
    }
    return $flattened;
}

/**
 * Renders straight table without attempting to decode
 * @param  $id
 * @param array $header
 * @param  $data
 * @return string
 */
function renderParticipantTable($id, $header = array(), $data) {
    // Render table
    $grid = '<table id="' . $id . '" class="table table-striped table-bordered table-condensed" cellspacing="0" width="95%">';
    $grid .= renderHeaderRow($header, 'thead');
    $grid .= renderSummaryTableRows($data);
    $grid .= '</table>';

    return $grid;
}

function renderHeaderRow($header = array(), $tag) {
    $row = '<' . $tag . '><tr>';
    foreach ($header as $col_key => $this_col) {
        $row .= '<th>' . $this_col . '</th>';
    }
    $row .= '</tr></' . $tag . '>';
    return $row;
}

function renderSummaryTableRows($row_data) {
    global $module;
    $rows = '';

    foreach ($row_data as $participant => $data) {
        $rows .= '<tr><td>' . $participant. '</td>';
        foreach ($data as $k => $v) {
            //$module->emDebug($k, $v);
            switch ($k) {

                case "withdrawn":

                    switch ($v) {
                        case '0':
                            $v = 'No';
                            break;
                        case '1':
                            $v = 'Yes';
                            break;
                    }

                    $rows .= '<td>' . $v . '</td>';
                    break;
                default:
                    $rows .= '<td>' . $v . '</td>';
            }
        }

        $rows .= '</tr>';
    }
    return $rows;
}

//display the table
//include "pages/report_page.php";

?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo $module->getModuleName()?></title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>

    <!-- Bootstrap core CSS -->
    <link rel="stylesheet" type="text/css" media="screen" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php print $module->getUrl("favicon/stanford_favicon.ico",false,true) ?>">

    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="<?php print $module->getUrl("js/jquery-3.2.1.min.js",false,true) ?>"></script>

    <!-- Include all compiled plugins (below), or include individual files as needed -->
    <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js'></script>

    <!-- Include DataTables for Bootstrap -->
    <script src="<?php print $module->getUrl("js/datatables.min.js", false,true) ?>"></script>
    <!--    <link href="--><?php //print $module->getUrl('css/datatables.min.css', false, true) ?><!--"  rel="stylesheet" type="text/css" media="screen,print"/>-->
    <style><?php echo $module->dumpResource('css/datatables.min.css'); ?></style>


    <!-- Add local css and js for module -->
</head>
<body>
<div class="container">
    <div class="jumbotron">
        <h3>Weekly Goal Report</h3>
    </div>
</div>

<div class="container">
    <?php print renderParticipantTable("summary", $table_header, $table_data) ?>
</div>
</body>

<script type = "text/javascript">

    $(document).ready(function(){
        $('#summary').DataTable();
    });

</script>


