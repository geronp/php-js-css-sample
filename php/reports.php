<?php

require_once('../lib/common.php');

class ReportsProxy extends AjaxProxy
{

    public function get_facility_hours(): array
    {
        global $accounts, $reports;
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        // if (!$accounts->has_access('Report - Facility Hours Report')) {
        //     return $this->get_error_response('Access denied');
        // }
        $ret = $this->get_response();
        // $ret['data'] = $reports->

        return $ret;
    }

    public function get_facility_type(): array {
        global $accounts, $reports;
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }

        $accounts->update_session();

        $ret = $this->get_response();
        $sort = $this->sort_default('name', 'ASC');
        $filter = array();
        $ret['data'] = $reports->get_facility_types($filter, $sort);

        return $ret;
    }

    public function get_tci_report(): array {
        global $accounts, $reports;

        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }
        $accounts->update_session();
        $filter = array(
            'facility_class_id' => $this->get_get_param('facility_class_id'),
            'start_date'        => $this->get_get_param('start_date'),
            'end_date'          => $this->get_get_param('end_date'),
            'facility_code_id'  => $this->get_get_param('facility_code_id'),
        );
        $sort = $this->sort_default('facility_code', 'ASC');

        $ret = $this->get_response();
        $ret['data'] = $reports->get_facility_tci($filter, $sort);
        $ret['total'] = count($ret['data']);

        return $ret;
    }

    public function get_tci_advance_report(): array {
        global $accounts, $reports;

        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }
        $accounts->update_session();

        $tci_id = $this->get_get_param('tciid');
        $facility_code = $this->get_get_param('facilityCode');
        $facility = $this->get_get_param('facility');
        $facility_class = $this->get_get_param('facilityClass');
        $ret = $this->get_response();
        $ret['data'] = $reports->tci_advance_report($tci_id, $facility, $facility_class, $facility_code);

        return $ret;
    }

    public function get_facility_codes(): array {
        global $accounts, $reports;
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }
        $accounts->update_session();

        $ret = $this->get_response();
        $ret['data'] = $reports->get_facility_code_ids();

        return $ret;
    }

    public function get_graph_trend(): array {
        global $accounts;
        global $reports;

        if (!$accounts->is_session_valid()) {
            return $this->get_error_response("You are not logged in");
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }

        $accounts->update_session();

        $facility_id = $this->get_get_param_int('facility_id');
        $filters = $this->get_get_param_int('display', 'default');
        $start_date = $this->get_get_param('start_date', '');
        $end_date = $this->get_get_param('end_date', '');
        $dates = $reports->get_months_between_dates($start_date, $end_date);
        $dates['duration'] = (int) $dates['duration'] === 0 ? 13 : $dates['duration'];
        $ret = $this->get_response();
        $ret['data'] = empty($facility_id) ? [] : $reports->get_facility_tci_trend($facility_id, $dates, $filters);    
        $ret["total"] = count($ret['data']);

        return $ret;
    }

    public function get_HoOA_report(): array {
        global $accounts;
        global $reports;

        if (!$accounts->is_session_valid()) {
            return $this->get_error_response("You are not logged in");
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }

        $accounts->update_session();

        $facility_id = $this->get_get_param_int('facility_id');
        $filters = array(
            'day'   => $this->get_get_param_int('day', ''),
            'tz'    => $this->get_get_param('tz', ''),

        );
        $dates = array(
            'start_date'    => $this->get_get_param('start_date', ''),
            'end_date'      =>  $this->get_get_param('end_date', '')
        );
        $ret = $this->get_response();
        $ret['data'] = empty($facility_id) ? [] : $reports->get_HoOA_report($facility_id, $dates, $filters);
        $ret['total'] = count($ret['data']);

        return $ret;
    }


    public function get_graph_trend_fpl(): array {
        global $accounts;
        global $reports;

        if (!$accounts->is_session_valid()) {
            return $this->get_error_response("You are not logged in");
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }

        $accounts->update_session();

        $facility_id = $this->get_get_param_int("facility_id");
        $duration = $this->get_get_param_int("duration", 12);
        $ret = $this->get_response();
        $ret['data'] = ['fpl' => 7];
        $ret["total"] = $duration;

        return $ret;
    }

    public function get_fpl_facility_codes(): array {
        global $accounts, $reports;

        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        // if (!$accounts->has_access('Report - TCI Report')) {
        //     return $this->get_error_response('Access denied');
        // }
        $term = $this->get_get_param('term', null);
        $ret = $this->get_response();
        $ret['data'] = $reports->get_fpl_facility_code_ids($term);

        return $ret;
    }

    public function get_tci_change_report(): array {
        global $accounts, $reports;

        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }
        $accounts->update_session();

        $filters = array(
            'facilityIds'   => $this->get_get_param('facilityIds', false),
            'fpl'           => $this->get_get_param_int('facilityFPL', false),
            'facilityType'  => $this->get_get_param('facilityTypeId', false),
        );
        $dates = array(
            'start_date'    => $this->get_get_param('start_date', null),
            'end_date'      =>  $this->get_get_param('end_date', null)
        );
        $filters = array_filter($filters); //Empty values removed
        $dateData = $reports->get_months_between_dates($dates['start_date'], $dates['end_date']);
        if ($dateData['duration'] < 4) {
            $ret = $this->get_error_response('Error with the dates must be at larger than 3 months span');
        } 
        elseif (empty($filters)) {
            $ret = $this->get_error_response('Please select a facility option');
        }
        else {
            $ret = $this->get_response();
            $data = empty($filters) ? [] : $reports->get_tci_change_report($dates, $filters);
            if (!empty($data)) {
                $ret['metaData'] = $data['metaData'] ?? null;
                $ret['data'] = $data['data'];
                $ret['total'] = count($ret['data']);
            }
            else { 
                $data['total'] = 0;
            }
        }

        return $ret;
    }

    public function get_tci_change_report_chart(): array {
        global $accounts, $reports;

        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        if (!$accounts->has_access('Report - TCI Report')) {
            return $this->get_error_response('Access denied');
        }
        $accounts->update_session();

        $filters = array(
            'facilityIds'   => $this->get_get_param('facilityIds', false),
            'fpl'           => $this->get_get_param_int('facilityFPL', false),
            'facilityType'  => $this->get_get_param('facilityTypeIds', false),
        );
        $dates = array(
            'start_date'    => $this->get_get_param('start_date', null),
            'end_date'      =>  $this->get_get_param('end_date', null)
        );
        $filters = array_filter($filters); //Empty values removed
        $dateData = $reports->get_months_between_dates($dates['start_date'], $dates['end_date']);
        if ($dateData['duration'] < 4) {
            $ret = $this->get_error_response('Error with the dates must be at larger than 3 months span');
        } 
        elseif (empty($filters)) {
            $ret = $this->get_error_response('Please select a facility option');
        }
        else {
            $ret = $this->get_response();
            $data = empty($filters) ? [] : $reports->get_tci_change_report_chart($dates, $filters);
            if (!empty($data)) {
                $ret['metaData'] = $data['metaData'] ?? null;
                $ret['data'] = $data['data'];
                $ret['total'] = count($ret['data']);
            }
            else { 
                $data['total'] = 0;
            }
        }

        return $ret;
    }

    public function get_fpl_effective_dates(): array {
        global $accounts, $reports;
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        // if (!$accounts->has_access('Report - Facility FPL Report')) {
        //     return $this->get_error_response('Access denied');
        // }
        $ret = $this->get_response();
        $ret['data'] = [['adaptation_id' => '1', 'effective_date' => '2020-01-09'], ['adaptation_id' => '3', 'effective_date' => '2020-03-01']];

        return $ret;
    }

    public function get_facility_fpl_report(): array {
        global $accounts, $reports;
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        // if (!$accounts->has_access('Report - Facility FPL Report')) {
        //     return $this->get_error_response('Access denied');
        // }
        $accounts->update_session();

        $term = $this->get_get_param('term');
        $ret = $this->get_response();
        $ret['data'] = $reports->get_fpl_facility_report($term);


        return $ret;
    }

    public function get_facility_profile_report(): array {
        global $accounts, $reports;
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        // if (!$accounts->has_access('Report - Facility Profile Report')) {
        //     return $this->get_error_response('Access denied');
        // }
        $accounts->update_session();

        $params['term'] = $this->get_get_param('term');
        $params['start_date'] = $this->get_get_param('start_date');
        $params['end_date'] = $this->get_get_param('end_date');
        $params['facility_code'] = $this->get_get_param('facility_code');
        $params['selected_date'] = $this->get_get_param('selectedDate');
        $sort = $this->sort_default('effective_start_date');
        $ret = $this->get_response();
        $ret['data'] = $reports->get_facility_profile_report($params, $sort);
        $ret['total'] = count($ret['data']);

        return $ret;
    }

    private function sort_default(string $sort = null, string $direction = 'DESC'): array {
        return array(
            'sort' => $this->get_get_param('sort', $sort),
            'dir' => $this->get_get_param('dir', $direction),
            'start' => $this->get_get_param_int('start'),
            'limit' => $this->get_get_param_int('limit')
        );
    }
    
    public function get_average_count_report(): array {
        global $accounts, $reports;
        
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        $accounts->update_session();

        $params['start_date'] = $this->get_get_param('start_date');
        $params['end_date'] = $this->get_get_param('end_date');
        $params['facility_id'] = $this->get_get_param_int('facility_id');
        $params['selected_type'] = $this->get_get_param('selectedType', "hourly");
        $params["facility_class_id"] = $this->get_get_param_int("facility_class_id");
        $sort = $this->sort_default('hour');
        
        if($params["facility_id"] === 0) {
            return $this->get_error_response("Facility ID is required");
        }
        if(strlen($params["start_date"]) === 0) {
            return $this->get_error_response("Start Date is required");
        }
        if(strlen($params["end_date"]) === 0) {
            return $this->get_error_response("End Date is required");
        }
        
        $ret = $this->get_response();
        $ret['data'] = $reports->get_average_count_report($params, $sort);
        $ret['total'] = count($ret['data']);

        return $ret;
    }
    
    public function get_manual_count_report(): array {
        global $accounts, $reports;
        
        if ($accounts->is_session_valid() === false) {
            return $this->get_error_response('You are not logged in');
        }
        $accounts->update_session();

        $params['start_date'] = $this->get_get_param('start_date');
        $params['end_date'] = $this->get_get_param('end_date');
        $params['facility_id'] = $this->get_get_param_int('facility_id');
        $params['selected_type'] = $this->get_get_param('selectedType', "hourly");
        $params['facility_class_id'] = $this->get_get_param_int('facility_class_id');
        $sort = $this->sort_default('date');
        
        if($params["facility_id"] === 0) {
            return $this->get_error_response("Facility ID is required");
        }
        if(strlen($params["start_date"]) === 0) {
            return $this->get_error_response("Start Date is required");
        }
        if(strlen($params["end_date"]) === 0) {
            return $this->get_error_response("End Date is required");
        }
        
        $ret = $this->get_response();
        $reportdata = $reports->get_manual_count_report($params, $sort);
        //$ret['data'] = $reportdata["data"];
        $ret = $reportdata;
        $ret['total'] = count($ret['data']);
        
        return $ret;
    }
}

$proxy = new ReportsProxy();
$proxy->route_request();
