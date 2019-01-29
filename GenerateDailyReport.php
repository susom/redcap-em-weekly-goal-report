<?php
namespace Stanford\WeeklyGoalReport;
/** @var \Stanford\MissingDiaryReport\WeeklyGoalReport $module */

use \REDCap as REDCap;

//include "common.php";
include_once("classes/StaticUtils.php");

$begin = '';
$end = '';

//check if in context of record. if not report error
//Plugin::log($project_id, "DEBUG", "PROJECT ID");


if(isset($_POST['submit']))
{

    $begin = new \DateTime($_POST["start_date"]);
    $end = new \DateTime($_POST["end_date"]);
    $today = new \DateTime();
    if ($end > $today) {
        $end = $today;
    }
    $begin_str = $begin->format('Y-m-d');
    $end_str = $end->format('Y-m-d');

}


if ($end != '') {

    $interval = \DateInterval::createFromDateString('1 day');
    $period = new \DatePeriod($begin, $interval, $end);

    foreach ($period as $dt) {
        $dow = $dt->format("l");
        $dates[]= $dt->format("Y-m-d");
        $dates_day[] = $dt->format("Y-m-d") . " - " . $dow;
    }
    $cfg_orig  = $module->getProjectSettings($project_id);

    //convert the $cfg into the version like the em
    $cfg = convertConfigToArray($cfg_orig);
    //$module->emDebug($cfg);

    ///////////// NEW WAY SINCE TAKING TOO LONG  /////////////////////
    //1. Get all survey data from survey project
    $surveys = $module->getAllSurveys(null, $cfg);

    //2. Get list of participants from surveys
    $participants = WeeklyGoalReport::getUniqueParticipants($cfg['SURVEY_FK_FIELD'], $surveys);

    //3. Get survey portal data from main project
    $portal_fields = array(REDCap::getRecordIdField(),$cfg['START_DATE_FIELD'],$cfg['END_DATE_FIELD']);
    $portal_params = array(
        'project_id'    => $project_id,
        'return_format' => 'json',
        'fields'        =>$portal_fields,
        'events'        => $cfg['PARTICIPANT_EVENT_ARM_NAME']
    );
     $q = REDCap::getData($portal_params);
     $portal_data_orig = json_decode($q, true);

    //$portal_data_orig = StaticUtils::getFieldValues($project_id, $portal_fields, $cfg['START_DATE_EVENT'], $cfg['PARTICIPANT_EVENT_ARM_NAME']);
    //rearrange so that the id is the key
    $portal_data = StaticUtils::makeFieldArrayKey($portal_data_orig, REDCap::getRecordIdField());

    //4. reorganize so that it's keyed by id - survey_date
    $surveys_by_id = $module->arrangeSurveyByID($surveys, $portal_data, $cfg['START_DATE_FIELD'],
        $cfg['SURVEY_PK_FIELD'], $cfg['SURVEY_FK_FIELD'], $cfg['SURVEY_DATE_FIELD'], $cfg['SURVEY_DAY_NUMBER_FIELD'],
        $cfg['SURVEY_FORM_NAME'].'_complete');
//    $module->emDebug($surveys_by_id, "ALL SURVEYS by ID" . $cfg['SURVEY_FK_FIELD']); exit;


    //$survey_data = WeeklyGoalReport::arrangeSurveyByIDWeek($surveys, $cfg['SURVEY_FK_FIELD'], $cfg['SURVEY_DATE_FIELD']);
    //$module->emDebug($survey_data, "ALL SURVEYS by ID by week" . $cfg['SURVEY_FK_FIELD']);

    //assemble the table and fill in the missed required days
    //participant on y axis
    //survey status on x axis
    $table_data = array();

    foreach ($participants as $participant) {
        $table_data[$participant] = $module->getAttendedDayNumbers(
            $surveys_by_id[$participant],
            $portal_data[$participant][$cfg['START_DATE_FIELD']],
            $portal_data[$participant][$cfg['END_DATE_FIELD']],
            $participant
        );
    }


    //$module->emDebug($portal_data); exit;
    $table_header = array_merge(array("Participant", "Start", "End"), $dates_day);


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
function renderParticipantTable($id, $header = array(), $data, $date_window) {
    // Render table
    $grid = '<table id="' . $id . '" class="table table-striped table-bordered table-condensed" cellspacing="0" width="95%">';
    $grid .= renderHeaderRow($header, 'thead');
    $grid .= renderSummaryTableRows($data, $date_window);
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

/**
 * @param $row_data
 * @param $date_window  window specified in UI
 * @return string
 */
function renderSummaryTableRows($row_data, $date_window) {

    global $module;
    $rows = '';

    foreach ($row_data as $participant => $dates) {
        $rows .= '<tr><td>' . $participant. '</td>';
        $rows .= '<td>'.$dates['start'].'</td>';
        $rows .= '<td>'.$dates['end'].'</td>';

        foreach ($date_window as $display_date) {

            $status = $dates[$display_date]['STATUS'];
            $day_num = $dates[$display_date]['DAY_NUMBER'];

            $status_unscheduled = '';
            $status_blue = '<button type="button" class="btn btn-info btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';
            $status_yellow = '<button type="button" class="btn btn-warning btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';
            $status_green = '<button type="button" class="btn btn-success btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';
            $status_red = '<button type="button" class="btn btn-danger btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';

            switch ($status) {
                    case "-1":
                        $rows .= '<td>' . $status_red .  '</td>';
                        break;
                    case '0':
                        $rows .= '<td>' . $status_yellow . '</td>';
                        break;
                    case '1':
                        $rows .= '<td>' . $status_blue . '</td>';
                        break;
                    case '2':
                        $rows .= '<td>' . $status_green . '</td>';
                        break;
                    default:
                        $rows .= '<td>' . $status_unscheduled . '</td>';
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



    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php print $module->getUrl("favicon/stanford_favicon.ico",false,true) ?>">


    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <!--script src="<?php print $module->getUrl("js/jquery-3.2.1.min.js",false,true) ?>"></script-->

    <!-- Include all compiled plugins (below), or include individual files as needed -->
    <!--
    <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js'></script>
-->
    <!-- Include DataTables for Bootstrap -->
    <!--
    <script src="<?php print $module->getUrl("js/datatables.min.js", false, true) ?>"></script>
    <style><?php echo $module->dumpResource('css/datatables.min.css'); ?></style>
    -->

    <script type="text/javascript" src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.18/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.18/js/dataTables.bootstrap4.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.4/js/dataTables.buttons.min.js"></script>
    <!--script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.4/js/buttons.bootstrap4.min.js"></script-->
    <script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.4/js/buttons.html5.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/fixedcolumns/3.2.5/js/dataTables.fixedColumns.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/fixedheader/3.1.4/js/dataTables.fixedHeader.min.js"></script>


    <!-- Bootstrap core CSS -->
    <link rel="stylesheet" type="text/css" media="screen" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <!--
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.18/css/dataTables.bootstrap4.min.css"/>
    -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/1.5.4/css/buttons.bootstrap4.min.css"/>

    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/fixedcolumns/3.2.5/css/fixedColumns.bootstrap4.min.css"/>
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/fixedheader/3.1.4/css/fixedHeader.bootstrap4.min.css"/>



    <!-- Bootstrap Date-Picker Plugin -->
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/js/bootstrap-datepicker.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/css/bootstrap-datepicker3.css"/>

    <style><?php echo $module->dumpResource('css/weeklygoal.css'); ?></style>



    <!-- Add local css and js for module -->
</head>
<body>
<div class="container">
    <div class="jumbotron">
        <h3>Missed Diary Report</h3>
    </div>
    <form method="post">
    <div class="well">
        <div class="container">
            <div class='col-md-4'>
                <div class="form-group">
                    <label>START</label>
                    <div class='input-group date' id='datetimepicker6'>
                        <input name="start_date" type='text' class="form-control" />
                        <span class="input-group-addon">
                    <span class="glyphicon glyphicon-calendar"></span>
                </span>
                    </div>
                </div>
            </div>
            <div class='col-md-4'>
                <div class="form-group">
                    <label>END</label>
                    <div class='input-group date' id='datetimepicker7'>
                        <input name="end_date" type='text' class="form-control" />
                        <span class="input-group-addon">
                    <span class="glyphicon glyphicon-calendar"></span>
                </span>
                    </div>
                </div>
            </div>
        </div>
        <input class="btn btn-primary" type="submit" value="START" name="submit">
    </div>
    </form>

</div>

<div class="container">
    <?php print renderParticipantTable("summary", $table_header, $table_data, $dates) ?>
</div>
</body>

<script type = "text/javascript">

    $(document).ready(function(){

        $('#summary').DataTable( {
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf'
            ],
            scrollY:        "600px",
            scrollX:        true,
            scrollCollapse: true,
            paging:         false,
            fixedColumns:   {
                leftColumns: 3
            }
        } );

        $('#datetimepicker6').datepicker({
            format: 'yyyy-mm-dd'

        });
        $('#datetimepicker7').datepicker({
            format: 'yyyy-mm-dd'
        });

        $('input[name="start_date"]').val("<?php echo $begin_str?>");
        $('input[name="end_date"]').val("<?php echo $end_str?>");

    });


</script>



