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

    //if current week is not set (completed), use Total Weeks (completed)
    $multiplier_week =  ($current_week == '') ?  $total_week : $current_week;

    //todo: check with myo. Report total count of surveys as actual Attendance
    $actual_attendance = $survey_data[$participant]['count'];
    //$module->emLog($survey_data[$participant], 'SURVEY DATA PARTICIPANT');
    //$module->emLog($counts[$participant], 'counts participant for ' .$participant);

    //$module->emLog('start is '.$start_date. ' week '.$begin->format('Y_W'));
    //$module->emLog('today is '. $today. ' week '.$today->format('Y_W'));



    //total count over expected
    //TODO: php intl extension not enabled in dev machine?
    //$percent_formatter = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
    $overall_adherence = $actual_attendance / ($multiplier_week * $multiplier);
    $count_weeks = count($counts[$participant]);

    //$capped_count = array_sum(array_column($counts[$participant],'capped_count'));

    //make sure capped count is constrained by the start and end date
    $capped_current_attendance = currentAttended($counts[$participant], $begin, $today);
    $weekly_adherence = $capped_current_attendance / ($multiplier_week * $multiplier);

    $five_wk_count = lastFiveAttended($counts[$participant]);
    $five_wk_adherence = $five_wk_count / (5 * $multiplier); //sometimes not 5 yet attendended

    //$module->emDebug($participant, "PARTICIPANT DATA".$cfg['WITHDRAWN_STATUS_FIELD'].'___1'.$cfg['GROUP_FIELD']);
    $table_data[$participant][$cfg['WITHDRAWN_STATUS_FIELD']] = $participant_data[$participant][$cfg['WITHDRAWN_STATUS_FIELD'].'___1'];
    $table_data[$participant][$cfg['GROUP_FIELD']]            = $group;
    $table_data[$participant][$cfg['START_DATE_FIELD']]       = $start_date;
    $table_data[$participant][$cfg['END_DATE_FIELD']]         = $end_date;
    $table_data[$participant]['current_week']                 = $current_week;
    $table_data[$participant]['total_weeks']                  = $total_week;
    $table_data[$participant]['expected_attendance']          = $multiplier_week * $multiplier ;


    //$table_data[$participant]['capped_current_attendance']    = $capped_current_attendance;

    $table_data[$participant]['current_attendance']           = $actual_attendance;  //actual over current week
    //$table_data[$participant]['overall_adherence']            = $percent_formatter->format($overall_adherence);
    $table_data[$participant]['overall_adherence']            = sprintf("%.2f%%", $overall_adherence * 100);

    $table_data[$participant]['count']                        = $capped_current_attendance; //capped
    //$table_data[$participant]['weekly_adherence']             =  $percent_formatter->format($weekly_adherence);
    $table_data[$participant]['weekly_adherence']             = sprintf("%.2f%%", $weekly_adherence * 100);
    $table_data[$participant]['70_adherence']                 = $weekly_adherence >= .7 ? 1 : 0;

    $table_data[$participant]['five_week_count']              = $five_wk_count; //capped
    //$table_data[$participant]['five_week_adherence']          = $percent_formatter->format($five_wk_adherence);
    $table_data[$participant]['five_week_adherence']          = sprintf("%.2f%%", $five_wk_adherence * 100);


}

//$module->emLog($table_data, "table DATA");

$sum_weekly_adherence = array_sum(array_column($table_data,'count'));
$sum_expected_adherence = array_sum(array_column($table_data,'expected_attendance'));
$sum_70_adherence = array_sum(array_column($table_data,'70_adherence'));
$count_participant = count($table_data);
$adherence_70_percent = sprintf("%.2f%%", ($sum_70_adherence/$count_participant) * 100);
$adherence_weekly_percent = sprintf("%.2f%%", ($sum_weekly_adherence/$sum_expected_adherence) * 100);


//$module->emDebug($table_data, "ALL TABLE DATA");

$table_header = array("Participant","Withdraw Status","Arm","Start Date","End Date",
    "Current Week", "Total Weeks", "Expected Attendance","Attendance Count", "Overall Adherence",
    "Count of Attendance (capped)", "Overall Adherence (count capped)", "70% Weekly Adherence", "5 week Count (capped)", "5 week adherence" );


/**
 * Sum the capped attendance from start to current date
 *
 * @param $survey_data
 * @DateTime $start_date
 * @DateTime $end_date
 */
function currentAttended($survey_data, $begin, $end) {
    global $module;
    $sum_attended = 0;

    //iterate over duration
    //$module->emDebug("begin : ".$begin->format('Y_W')) ."/ end: ".$end->format('Y-W');
    //echo "begin : ".$begin->format('Y-m-d') . "/ end: ".$end->format('Y-m-d-');

    $daterange = new \DatePeriod($begin, new \DateInterval('P1W'), $end);

    foreach($daterange as $date){
        $yr_week = $date->format('Y_W');

        $sum_attended += $survey_data[$yr_week]['capped_count'];
    }

    return $sum_attended;


}

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
                case "70_adherence":

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
        <h2>Weekly Goal Report</h2>
        <table>
            <tr>
                <td>Count of Participants:</td>
                <td>  <?php print $count_participant?></td>
            </tr>

            <tr>
                <td>Sum of capped attendance (all participants):  </td>
                <td>  <?php print $sum_weekly_adherence?></td>
            </tr>
            <tr>
                <td>Sum of expected attendance (all participants):  </td>
                <td>   <?php print $sum_expected_adherence?> </td>
            </tr>
            <tr>
                <td> Average Weekly Adherence:  </td>
                <td>  <?php print $adherence_weekly_percent?></td>
            </tr>

            <tr>
                <td> Count of participants with 70% Weekly Adherence:  </td>
                <td>  <?php print $sum_70_adherence?></td>
            </tr>
            <tr>
                <td> Percent of participants with 70% Weekly Adherence :  </td>
                <td>  <?php print $adherence_70_percent?> </td>
            </tr>
        </table>

    </div>
</div>

<div class="container">
    <?php print renderParticipantTable("summary", $table_header, $table_data) ?>
</div>
</body>

<script type = "text/javascript">
    $(document).ready(function() {
        $('#summary').DataTable( {
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ]
        } );
    } );
</script>


