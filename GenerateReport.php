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

$withdrawn = false;
$vacation = false;
$illness = false;
$future_vacation = false;

$withdrawn = (isset($_POST['withdrawn'])) ? true : null;
$vacation = (isset($_POST['vacation'])) ? true : null;
$illness = (isset($_POST['illness'])) ? true : null;
$future_vacation = (isset($_POST['future_vacation'])) ? true : null;



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
$participant_data = WeeklyGoalReport::getParticipantData(null, $cfg, $withdrawn);

//rearrange so that the id is the key
$participant_data = StaticUtils::makeFieldArrayKey($participant_data, $cfg['SURVEY_PK_FIELD']);
//$module->emDebug($participant_data, "PARTICIPANT DATA");

//1. Get all survey data from survey project
$surveys = WeeklyGoalReport::getAllSurveys(null, $cfg);
//$module->emDebug($surveys, "ALL SURVEYS");

//rearrange so that the id is the key with year and week number as nested arrays
//todo: the default behaviour of the DateTime format("W") is to set week from Monday to Sunday
$survey_data = WeeklyGoalReport::arrangeSurveyByIDWeek($surveys, $cfg['SURVEY_FK_FIELD'], $cfg['SURVEY_DATE_FIELD']);
//$module->emDebug($survey_data, "ALL SURVEYS by ID by week" . $cfg['SURVEY_FK_FIELD']); exit;


//addendum : request from Lida to pull out vacation days. If any vacation is taken, ignore the entire week.
//if vacation/illness checkbox are checked
//if checked, remove all the weeks on vacation.

//use the vacation days project to remove those weeks that the participant is missing.
$vacation_pid = $module->getProjectSetting('vacation_pid');

if ($vacation_pid != null) {
    //get the vacation / illness days from the vacation pid project
    $excluded_days = $module->getVacationData($vacation_pid);

    //rearrange the excluded_weeks by year_week.
    $excluded_weeks = $module->getExcludedWeeks($excluded_days, $vacation, $illness, $future_vacation);
    //$module->emDebug($excluded_weeks, "EXCLUDED WEEK");

}


//2. Get list of participants from surveys
//$participants = WeeklyGoalReport::getUniqueParticipants($cfg['SURVEY_FK_FIELD'], $surveys);
$participants = array_keys($participant_data);
//$module->emDebug($participants, "ALL PARTICIPANTS"); exit;

// COUNTS table is the final summary of participation and absences
/**
(
    [2151-0000] => Array
        (
            [201646] => Array
                (
                    [raw_count] => 3
                    [capped_count] => 3
                    [adhered] => 1
                    [absent] => 0
                )

            [201647] => Array
                (
                    [raw_count] => 0
                    [capped_count] => 0
                    [adhered] => 0
                    [absent] => 0
                )
**/
$counts= $module->calcCappedCount($survey_data, $participant_data, $cfg);

//Add in the vacation information to the capped count
$counts = $module->countWithAbsence($counts, $excluded_weeks);
//$module->emDebug($counts, "ALL COUNTS"); exit;

$coordinators = $module->getCoordinatorDecoded();


//assemble table to display
$table_data = array();
$today = new DateTime();

foreach ($participants as $participant) {
    //reset values
    $total_week = '';
    $current_week = '';

    //check on the withdrawn status of the participant


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
    } else {
        $end = $today;
    }

    //calculate the diff between start and today (Current Week)
    //only if the end date is past today
    if ($end > $today) {
        $current_week = $module->getDateDiffWeeks($begin, $today);
        $end = $today;
    }

    //calculate count of missed_weeks
    $absent_weeks =$module->countAbsentWeeks($counts[$participant]);

    //if current week is not set (completed), use Total Weeks (completed)
    $multiplier_week =  ($current_week == '') ?  $total_week : $current_week;

    //what number of weeks to use in equations
    $considered_weeks = $multiplier_week - $absent_weeks;

    $expected_attendance= $considered_weeks * $multiplier;

    //todo: check with myo. Report total count of surveys as actual Attendance
    $actual_attendance = $survey_data[$participant]['count'];

    //total count over expected
    //TODO: php intl extension not enabled in dev machine?
    //$percent_formatter = new \NumberFormatter('en_US', \NumberFormatter::PERCENT);
    $overall_adherence = $actual_attendance / ($multiplier_week * $multiplier);
    $count_weeks = count($counts[$participant]);

    //make sure capped count is constrained by the start and end date
    $capped_current_attendance  = $module->sumCappedCount($counts[$participant]);

    $weekly_adherence = $capped_current_attendance / $expected_attendance;


    $five_wk_count = lastFiveAttended($counts[$participant]);
    $five_wk_adherence = lastFiveAdherence($counts[$participant]);

    //$module->emDebug($participant, "PARTICIPANT DATA".$cfg['WITHDRAWN_STATUS_FIELD'].'___1'.$cfg['GROUP_FIELD']);
    $table_data[$participant]['coordinator']                  = $coordinators[$participant];
    $table_data[$participant][$cfg['WITHDRAWN_STATUS_FIELD']] = $participant_data[$participant][$cfg['WITHDRAWN_STATUS_FIELD'].'___1'];
    $table_data[$participant][$cfg['GROUP_FIELD']]            = $group;
    $table_data[$participant][$cfg['START_DATE_FIELD']]       = $start_date;
    $table_data[$participant][$cfg['END_DATE_FIELD']]         = $end_date;
    $table_data[$participant]['current_week']                 = $current_week;
    $table_data[$participant]['total_weeks']                  = $total_week;
    $table_data[$participant]['missed_weeks']                 = $absent_weeks;
    $table_data[$participant]['considered_weeks']             = $considered_weeks;
    $table_data[$participant]['expected_attendance']          = $expected_attendance ;


    //$table_data[$participant]['capped_current_attendance']    = $capped_current_attendance;

    //Attendance Count (All reported attendance)
    //$table_data[$participant]['current_attendance']           = $actual_attendance;  //actual over current week
    //$table_data[$participant]['overall_adherence']            = $percent_formatter->format($overall_adherence);

    //Overall Attendance
    //$table_data[$participant]['overall_adherence']            = sprintf("%.2f%%", $overall_adherence * 100);

    //Count of Attendance (capped - constrained by weekly cap, start and end date

    $table_data[$participant]['count']                        = $capped_current_attendance; //capped
    //$table_data[$participant]['weekly_adherence']             =  $percent_formatter->format($weekly_adherence);

    //Overall Adherence (count capped)
    $table_data[$participant]['weekly_adherence']             = sprintf("%.2f%%", $weekly_adherence * 100);

    //70% Weekly Adherence
    $table_data[$participant]['70_adherence']                 = $weekly_adherence >= .7 ? 1 : 0;

    //5 week count of attendance
    $table_data[$participant]['five_wk_count']              = $five_wk_count;

    //5 week adherence
    $table_data[$participant]['five_week_adherence']          = sprintf("%.2f%%", $five_wk_adherence * 100);


}

//$module->emDebug($table_data, "table DATA"); exit;

$sum_weekly_adherence = array_sum(array_column($table_data,'count'));
$sum_expected_adherence = array_sum(array_column($table_data,'expected_attendance'));
$sum_70_adherence = array_sum(array_column($table_data,'70_adherence'));
$count_participant = count($table_data);
$adherence_70_percent = sprintf("%.2f%%", ($sum_70_adherence/$count_participant) * 100);
$adherence_weekly_percent = sprintf("%.2f%%", ($sum_weekly_adherence/$sum_expected_adherence) * 100);

//repeat that set of counts EXCLUDING rows where end_date is missing
//get table with non-empty end_date filed
$filtered_table_data = array_filter($table_data, function($arrayValue)  { return !empty($arrayValue['end_date']); } );
//$module->emDebug($table_data_has_end, "table DATA"); exit;


$filtered_sum_weekly_adherence = array_sum(array_column($filtered_table_data,'count'));
$filtered_sum_expected_adherence = array_sum(array_column($filtered_table_data,'expected_attendance'));
$filtered_sum_70_adherence = array_sum(array_column($filtered_table_data,'70_adherence'));
$filtered_count_participant = count($filtered_table_data);
$filtered_adherence_70_percent = sprintf("%.2f%%", ($filtered_sum_70_adherence/$filtered_count_participant) * 100);
$filtered_adherence_weekly_percent = sprintf("%.2f%%", ($filtered_sum_weekly_adherence/$filtered_sum_expected_adherence) * 100);

//$module->emDebug($table_data, "ALL TABLE DATA");

$table_header = array(
    "Participant",
    "Coordinator",
    "Withdraw Status",
    "Arm",
    "Start Date",
    "End Date",
    "Current Week",
    "Total Weeks",
    "Missed Weeks (due to vacation or illness)",
    "Considered Weeks (Total - Missed Weeks (if checked))",
    "Expected Attendance (Considered Weeks * Multiplier)",
    //"Attendance Count (All reported attendance)",
    //"Overall Adherence",
    "Count of Attendance (capped - constrained by weekly cap, start and end date)",
    "Capped Adherence (Count of Attendance / Expected Attendance)",
    "70% Weekly Adherence",
    "5 Week Count of Attendance",
    "5 Week Adherence" );

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

function lastFiveAdherence($survey_data) {

    global $module;
    krsort($survey_data);

    $last_five = array_slice($survey_data, 0, 5, true);
    $count = array_sum(array_column($last_five,'absent_adhered'));

    return $count/5;
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
    <title><?php echo $module->getModuleName() ?></title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>

    <!-- Bootstrap core CSS -->
    <link rel="stylesheet" type="text/css" media="screen"
          href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">

    <!-- Favicon -->
    <link rel="icon" type="image/png"
          href="<?php print $module->getUrl("favicon/stanford_favicon.ico", false, true) ?>">

    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="<?php print $module->getUrl("js/jquery-3.2.1.min.js", false, true) ?>"></script>

    <!-- Include all compiled plugins (below), or include individual files as needed -->
    <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js'></script>

    <!-- Include DataTables for Bootstrap -->
    <script src="<?php print $module->getUrl("js/datatables.min.js", false, true) ?>"></script>

    <style><?php echo $module->dumpResource('css/datatables.min.css'); ?></style>


    <!-- Add local css and js for module -->
    <style><?php echo $module->dumpResource('css/weeklygoal.css'); ?></style>

</head>
<body>
<div class="container">
    <div class="jumbotron">
        <h2>Weekly Goal Report</h2>

        <?php if ($withdrawn || $vacation || $illness || $future_vacation) { ?>
        <h4>EXCLUDED: </h4>
        <?php } ?>
        <?php if ($withdrawn) { ?>
            <h4> &bull; Participants who have withdrawn.</h4>
        <?php } ?>

        <?php if ($vacation) { ?>
            <h4> &bull; Reported vacation days.</h4>
        <?php } ?>

        <?php if ($illness) { ?>
            <h4> &bull; Reported illness days.</h4>
        <?php } ?>

        <?php if ($future_vacation) { ?>
            <h4> &bull; Reported future vacation days.</h4>
        <?php } ?>

<table class="smry_table">
  <tr>
    <th class="smry_header">Counts</th>
    <th class="smry_header_formula" style="width:40%">Formula</th>
    <th class="smry_header">All Participants</th>
    <th class="smry_header">Exclude rows with <br>MISSING end date</th>
  </tr>
  <tr>
    <td class="tg-0lax">Count of Participants</td>
    <td class="smry_formula">count('Participants')</td>
    <td class="smry_ct"><?php print $count_participant ?></td>
    <td class="smry_ct"><?php print $filtered_count_participant ?></td>
  </tr>
  <tr>
    <td class="tg-0lax">Sum of Capped Attendance</td>
      <td class="smry_formula">Sum('Count of Attendance') <br><i>-- constrained by weekly cap, start date and end/current date</i></td>
    <td class="smry_ct"><?php print $sum_weekly_adherence ?></td>
    <td class="smry_ct"><?php print $filtered_sum_weekly_adherence ?></td>
  </tr>
  <tr>
    <td class="tg-0lax">Sum of Expected Attendance</td>
      <td class="smry_formula">Sum('Expected Attendance') <br><i>-- Considered Weeks * Multiplier</i></td>
    <td class="smry_ct"><?php print $sum_expected_adherence ?></td>
    <td class="smry_ct"><?php print $filtered_sum_expected_adherence ?></td>
  </tr>
  <tr>
    <td class="tg-0lax">Average Weekly Adherence</td>
    <td class="smry_formula">Average('Capped Adherence')</td>
    <td class="smry_ct"><?php print $adherence_weekly_percent ?></td>
    <td class="smry_ct"><?php print $filtered_adherence_weekly_percent ?></td>
  </tr>
  <tr>
    <td class="tg-0lax">Count of participants with 70% Weekly Adherence</td>
    <td class="smry_formula">Count('70% Weekly Adherence' = YES)</td>
    <td class="smry_ct"><?php print $sum_70_adherence ?></td>
    <td class="smry_ct"><?php print $filtered_sum_70_adherence ?></td>
  </tr>
  <tr>
    <td class="tg-0lax">Percent of participants with 70% Weekly Adherence</td>
    <td class="smry_formula">Count with 70% Adherence / Count of Participants</td>
    <td class="smry_ct"><?php print $adherence_70_percent ?></td>
    <td class="smry_ct"><?php print $filtered_adherence_70_percent ?></td>
  </tr>
</table>
        <div id="choices">
            <form action="#" method="post">
                <div><input type="checkbox" name="withdrawn" id="withdrawn" value="true">Remove withdrawn participants
                    from calculation. </input>
                </div>
                <div>
                    <input type="checkbox" name="vacation" id="vacation" value="true">Remove vacation days from calculation. </input>
                </div>
                <div>
                    <input type="checkbox" name="illness" id="illness" value="true">Remove illness days from calculation. </input>
                </div>
                <div>
                    <input type="checkbox" name="future_vacation" id="future_vacation" value="true">Remove future vacation days from calculation. </input>
                </div>
                <div id="submit">
                    <input type="submit" name="submit" value="Recalculate"/>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="container">
    <?php print renderParticipantTable("summary", $table_header, $table_data) ?>
</div>
</body>

<script type = "text/javascript">
    var insertHtml = function (selector, html) {
        var targetElem = document.querySelector(selector);
        targetElem.innerHTML = html;
    };

    $(document).ready(function() {
        $('#summary').DataTable( {
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ]
        } );

        $( "#withdrawn" ).prop( "checked", <?php echo $withdrawn?> );

        $( "#vacation" ).prop( "checked", <?php echo $vacation?> );

        $( "#illness" ).prop( "checked", <?php echo $illness?> );

        $( "#future_vacation" ).prop( "checked", <?php echo $future_vacation?> );

    } );
</script>

