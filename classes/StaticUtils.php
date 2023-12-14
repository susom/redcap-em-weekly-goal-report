<?php
namespace Stanford\WeeklyGoalReport;

use \REDCap;

use \Plugin;

use \Project;
use \Survey;


class StaticUtils {


    // Because the plugin method assumes the current project_id context, I had to make this method to allow me
    // to specify an alternate project_id
    static function getEventIdFromName($project_id, $event_name) {
        //Plugin::log($event_name, "DEBUG", "Getting event for project ".$project_id);

        if (empty($event_name)) return NULL;
        $thisProj = new Project($project_id,false);
        $thisProj->loadEventsForms();
        $event_id_names = $thisProj->getUniqueEventNames();

        //Plugin::log($event_id_names, "DEBUG", "Getting event id names for project ".$project_id);

        $event_names_id = array_flip($event_id_names);
        return isset($event_names_id[$event_name]) ? $event_names_id[$event_name] : NULL;
    }


    /**
     * TODO: NOT USED
     * // THIS IS A REWRITE OF THE INIT FUNCTIONS function record exists to permit the passing of a project_id
    Check if a record exists in the redcap_data table
     *
     * @param $record
     * @param null $arm_num
     * @param null $project_id
     * @return bool
     */
    static function projectRecordExists($record, $arm_num=null, $project_id=null)
    {
        global $Proj;
        $thisProj = empty($project_id) ? $Proj : new Project($project_id);

        $data_table = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($thisProj->project_id) : "redcap_data";

        // Query data table for record
        $sql = "select 1 from $data_table where project_id = ".$thisProj->project_id." and field_name = '{$thisProj->table_pk}'
			and record = '" . prep($record) . "'";
        if (is_numeric($arm_num) && isset($thisProj->events[$arm_num])) {
            $sql .= " and event_id in (" . prep_implode(array_keys($thisProj->events[$arm_num]['events'])) . ")";
        }
        $sql .= " limit 1";
        $q = db_query($sql);
        return (db_num_rows($q) > 0);
    }


    /**
 * Get the value for a given field in a project
 *
 * @param      $id
 * @param      $pid
 * @param      $field
 * @param      $event
 * @param bool $missing_value
 * @return bool
 */
    static function getFieldValue($id, $pid, $field, $event, $missing_value = false) {
        // Determine day number
        $q = REDCap::getData(
            $pid,
            'json',
            $id,
            array( $field ),
            array( $event )
        );
        $results = json_decode($q,true);
        return isset($results[0][$field]) ? $results[0][$field] : $missing_value;
    }

    /**
     * Get the value for a given array of fields in a project
     * See getFieldValue to get a single field
     *
     * @param      $pid
     * @param      $fields_array Array of fields to retrieve
     * @param      $event
     * @param bool $missing_value
     * @return bool
     */
    static function getFieldValues($pid, $fields_array, $event) {
        // Determine day number
        $q = REDCap::getData(
            $pid,
            'json',
            null,
            $fields_array,
            array( $event )
        );
        $results = json_decode($q,true);
        return $results;
        //return isset($results[0][$field]) ? $results[0][$field] : $missing_value;
    }

    static function makeFieldArrayKey($data, $key_field) {
        $r = array();
        foreach ($data as $d) {
            $r[$d[$key_field]] = $d;
        }
        return $r;

    }

    /**
     * Reset timestamps for survey response
     *
     * @param $response_id
     * @param $record
     */
    static function resetSurvey($response_id, $record) {
        // Reset response timestamps so survey can be completed
        $sql = sprintf("update redcap_surveys_response set first_submit_time = null, completion_time = null where response_id=%s and record = '%s' limit 1",
            db_real_escape_string($response_id),
            db_real_escape_string($record)
        );
        $q = db_query($sql);
    }


    /**
     * @param $input    Sring like 1,2,3-55,44,67
     * @return mixed    An array with each number enumerated out [1,2,3,4,5,...]
     */
    static function parseRangeString($input) {
        $input = preg_replace('/\s+/', '', $input);
        $string = preg_replace_callback('/(\d+)-(\d+)/', function ($m) {
            return implode(',', range($m[1], $m[2]));
        }, $input);
        $array = explode(",",$string);
        return empty($array) ? false : $array;
    }


    /**
     * todo: unused
     *
     * Determine the next ID
     * If no prefix is supplied, it is a auto-number generator within the supplied arm_event
     * If a prefix is supplied, it returns the prefix or appends -2, -3, ... if it already exists
     * @param $pid                  The project
     * @param $id_field             The PK field
     * @param null $arm_event       The arm_event if longitudinal
     * @param string $prefix        If specified, this will become the next ID or -2,-3,-4.. will be added if it already exists
     * @return int|string
     */
    static function getNextId($pid, $id_field, $arm_event = NULL, $prefix = '') {
        $q = REDCap::getData($pid,'array',NULL,array($id_field), $arm_event);

        if ( !empty($prefix) ) {
            // A prefix is supplied - first check if it is used
            if ( !isset($q[$prefix]) ) {
                // Just use the plain prefix as the new record name
                return $prefix;
            } else {
                // Lets start numbering at 2 until we find an open record id:
                $i = 2;
                do {
                    $next_id = $prefix . "-" . $i;
                    $i++;
                } while (isset($q[$next_id]));
                return $next_id;
            }
        } else {
            // No prefix
            $new_id = 1;
            foreach ($q as $id=>$event_data) {
                if (is_numeric($id) && $id >= $new_id) $new_id = $id + 1;
            }
            return $new_id;
        }
    }





    static function startBootstrapPage($title, $header = '') {
        $html= <<<EOD
<!DOCTYPE html>
    <html>
        <head>
            <title>$title</title>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <meta name='apple-mobile-web-app-title' content='$title'>
            <link rel='apple-touch-icon' href='../favicon/apple-touch-icon-iphone-60x60.png'>
            <link rel='apple-touch-icon' sizes='60x60' href='../favicon/apple-touch-icon-ipad-76x76.png'>
            <link rel='apple-touch-icon' sizes='114x114' href='../favicon/apple-touch-icon-iphone-retina-120x120.png'>
            <link rel='apple-touch-icon' sizes='144x144' href='../favicon/apple-touch-icon-ipad-retina-152x152.png'>

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


    static function endBootstrapPage($footer = "") {
        $html=<<<EOD
            <!-- Include all compiled plugins (below), or include individual files as needed -->
            <script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js'></script>
            $footer
        </body>
    </html>
EOD;
        return $html;
    }




}