<?php

class Reports
{
    public function __construct()
    {
    }

    public function __destruct()
    {
    }

    /**
     * Facility profile latest effective dates 
     *
     * @param array|null $filter
     * @return array|null
     */
    public function get_facility_profile_latest_effective_date(?array $filter = NULL): ?array {
        $timestamped = false;
        global $database;

        $sql = 'SELECT fp1.facility_profile_id 
        FROM facility_profiles AS fp1 
        JOIN facilities f ON fp1.facility_id = f.facility_id 
        JOIN districts d ON f.district_id = d.district_id
        LEFT OUTER JOIN facility_profiles AS t2 ON fp1.facility_id = t2.facility_id 
        AND (fp1.effective_date_start < t2.effective_date_start 
        OR (fp1.effective_date_start = t2.effective_date_start AND fp1.facility_profile_id < t2.facility_profile_id))
        JOIN facility_types ft ON fp1.facility_type_id = ft.facility_type_id
        WHERE t2.facility_id IS NULL';
        //Unique facility_ids
        if ($filter !== null) {
            $sql .=  isset($filter['term']) && !empty($filter['term']) ?
                ' AND (f.id LIKE :term OR f.`name` LIKE :term OR ft.name LIKE :term OR d.name LIKE :term ) ' : '';
            $sql .= isset($filter['facility_code']) && !empty($filter['facility_code']) ?
                ' AND f.id = :facility_code ' : '';
            if ($filter['selected_date'] === 'request') {
                $sql .=  isset($filter['start_date']) && !empty($filter['start_date']) ? ' AND (fp1.effective_date_start BETWEEN :startDate AND :endDate) ' : '';
            } else {
                $timestamped = true;
                $filter['start_date'] = strtotime($filter['start_date']);
                $filter['end_date'] = strtotime($filter['end_date']);
                $sql .=  isset($filter['start_date']) && !empty($filter['start_date']) ? ' AND (fp1.rejected_on BETWEEN :startDate AND :endDate OR fp1.approved_on BETWEEN :startDate AND :endDate) ' : '';
            }
        }
        $sql .= ' ORDER BY fp1.effective_date_start DESC';

        $stmt = $database->prepare($sql);
        if (strpos($sql, ':term') !== false) {
            $term = '%' . $filter['term'] . '%';
            $stmt->bindValue(':term', $term, PDO::PARAM_INT);
        }
        if (strpos($sql, ':facility_code') !== false) {
            $stmt->bindValue(':facility_code', $filter['facility_code'], PDO::PARAM_INT);
        }
        if (strpos($sql, ':startDate') !== false && $timestamped === false) {
            $stmt->bindValue(':startDate', $filter['start_date'], PDO::PARAM_STR);
            $stmt->bindValue(':endDate', $filter['end_date'], PDO::PARAM_STR);
        } else if (strpos($sql, ':startDate') !== false && $timestamped === true) {
            $stmt->bindValue(':startDate', $filter['start_date'], PDO::PARAM_INT);
            $stmt->bindValue(':endDate', $filter['end_date'], PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function get_tci_spectrum(string $date, array $filters, string $view = 'vertical'): array {
        global $breakpoints;
        
        //Get Averages
        $dates = array(
            'start_date'=> $this->get_date_with_interval($date, '-6 month'), 
            'end_date'  => $date
        );
        $tci = $this->get_tci_spectrum_values($date, $filters);
        if (empty($tci) !== true) {
            if (strtolower($view) === 'circular') {
                array_walk($tci, array($this, 'get_tci_spectrum_radar'));
            }
            else {
                $facilityClasses = array_unique(array_column($tci, 'facility_class_id'));
                $tciAvg = $this->spectrum_tci_averages($dates, array_column($tci, 'facility'), $facilityClasses);
                array_walk($tci, array($this, 'map_spectrum_average'), $tciAvg);
                if (strtolower($view) === 'table') {
                    array_walk($tci, array($this, 'get_spectrum_tci_table_data'));
                }
            }
        }

        return $tci;
    }

    public function get_tci_spectrum_radar(&$rec, $index): array {
        global $breakpoints;
        $break_pts = $breakpoints->get_breakpoints_min_buffet_max();
        $index = array_search($rec['fpl'], array_column($break_pts, 'fpl'));
        if ($index !== false) {
            $bp = $break_pts[$index];
            $rec = array(
                'facility'  => $rec['facility'],
                'tci'       => $rec['tci'],
                'buffer'    => $bp['buffer'],
                'max'       => $bp['max'],
                'min'       => $bp['min'],
                'fpl'       => $rec['fpl'],
                'type'      => $rec['facility_type']               
            );
        }

        return $rec;
    }

    public function get_spectrum_tci_table_data(array &$tci, $index): ?array {
        global $breakpoints;
        $break_pts = $breakpoints->get_breakpoints_min_buffet_max();
        $index = array_search($tci['fpl'], array_column($break_pts, 'fpl'));
        if ($index !== false) {
            $bp = $break_pts[$index];
            if (intval($bp['min']) === 0) {// No min/buffer
                $cal['fromBuffer'] = 0;
                $cal['fromMin'] = 0;
                $cal['fromBreakpoint'] = 100 - number_format(($tci['tci']/$bp['max'] * 100), 2);
            }
            else {
                $cal = $this->breakpoint_percentage_between($tci['tci'], $bp);
            }
        }

        $tci = array(
            'tci' => $tci['tci'],
            'facility' => $tci['facility'],
            'fpl' => $tci['fpl'],
            'type' => $tci['facility_type'],
            'fromBuffer' => number_format($cal['fromBuffer'], 2),
            'fromMin' => number_format($cal['fromMin'], 2),
            'fromBreakpoint' => number_format($cal['fromBreakpoint'], 2),
        );
        return $tci;

    }

    public function spectrum_tci_averages(array $dates, array $facilities, array $facilityClasses): array {
        global $database;
        $sql = '';
        $qBuilder = array();
        $filterFacilities = array_unique($facilities);
        array_walk($filterFacilities, function (&$facility, $key) {
            $facility = ':'.$facility; //key binding for multiple classes
        });
        $binded = rtrim(implode(', ', $filterFacilities), ', ');
        $cQuery = 'SELECT f.id AS facility, f.facility_id, AVG(fte.`tci`) AS avg_tci, fte.facility_class_id AS fc_id 
                FROM facilities f 
                    JOIN facility_profiles fp USING (facility_id) 
                    JOIN facility_tcis ftci USING (facility_id) 
                    JOIN facility_tci_entries fte USING (facility_tci_id) 
                WHERE f.tci = 1 
                    AND ftci.date BETWEEN :start_date AND :end_date 
                    AND f.id IN (' . $binded .  ') 
                    AND fte.facility_class_id = vCLASS
                    GROUP BY facility' . "\n";
        foreach ($facilityClasses as $class) {
            $qBuilder[] = str_replace('vCLASS', $class, $cQuery);
        }
        $sql = implode("UNION ALL \n", $qBuilder) . ' ORDER BY facility';
        $stmt = $database->prepare($sql);
        $stmt->bindValue('start_date', $dates['start_date'], PDO::PARAM_STR);
        $stmt->bindValue('end_date', $dates['end_date'], PDO::PARAM_STR);
        foreach ($filterFacilities as $facility) {
            $stmt->bindValue($facility, ltrim($facility, ':'), PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Retrieve the data fro the TCI Rate Change Report
     *
     * @param array $dates
     * @param array $filter
     * @return array
     */
    public function get_tci_change_report(array $dates, array $filter = NULL): array {     
        $dates['query_date'] = $this->get_date_with_interval($dates['start_date'], '-12 month');
        $natRates = $this->get_national_rate_of_change($dates, $filter);
        if (empty($natRates) === false) {
            $facilities = $this->get_facility_rate_of_change($dates, $filter);
            if (!empty($facilities)) {
              $natRates['metaData']['color_code'] = $this->get_background_color_code($facilities['rates']);
              unset($facilities['rates']);
              array_unshift($natRates['metaData']['columns'], array('xtype' => 'rownumberer'));
              array_unshift($facilities, $natRates['data']);
            }
        }
        else {
            $facilities = [];
        }
        $natRates['data'] = $facilities;
        
        return $natRates;
    }

    public function get_tci_change_report_chart(array $dates, array $filter = NULL): array {     
        $dates['query_date'] = $this->get_date_with_interval($dates['start_date'], '-12 month');
        $dataset = $this->get_chart_national_change_rate($dates, $filter);
        $data = $this->get_facility_rate_of_change_chart($dates, $filter, $dataset);
        
		return $data;
    }

    public function get_chart_national_change_rate(array $dates, array $filter): ?array {
        $natAvg = $this->get_national_averages($dates, $filter);
        $data = array();
        if (is_array(($natAvg)) && !empty($natAvg)) {
            foreach ($natAvg as $key => $recs) {
                $source[] = $this->find_national_rate_changes($recs, $natAvg);
            }
            $metaData = array_column($source, 'metaData');
            $data['metaData'] = array(
                'fields'    => array_column($metaData, 'field'),
            );
            array_unshift($data['metaData']['fields'], array('name' => 'Nat. Avg.', 'type' => 'float', 'default' => 0));
            foreach (array_column($source, 'data') as $key => $record) {
                $data['data'][] = array(
                    'date'              => $record['date'],
                    $record['facility'] => $record['rate'],
                );
            }
        }
        
        return $data;
    }   

    public function get_facility_rate_of_change(array $dates, array $filter): ?array {
        $source = array();
        $findBy = !empty(array_keys($filter)) ? array_keys($filter)[0] : null;
        if ($findBy === 'fpl') {
            $data = $this->get_avg_tci_fpl($dates, $filter[$findBy]);
        }
        elseif ($findBy === 'facilityType') {
            $data = $this->get_avg_tci_facility_type($dates, $filter[$findBy]);
        }
        elseif ($findBy === 'facilityIds') {
            $facilityIds = json_decode($filter[$findBy]);
            $data = $this->get_averages_by_facilityIds($dates, $facilityIds);
        }
        if (!empty($data)) {
            foreach ($data as $key => $record) {
                $rate = $this->find_facility_rate_changes($record, $data);
                if ($rate !== null) { //Filter the empty 
                    $rates[] = $rate;
                }
            }
            $aRates = array();
            $i = null;
            foreach ($rates as $record) {
                if ($i === null) {
                    $i = 0;
                    $source[$i]['facility'] = $record['facility'];
                }
                elseif ($source[$i]['facility'] !== $record['facility']) {
                    $i++;
                    $source[$i]['facility'] = $record['facility'];  
                }
                $source[$i][$record['date']] = $record['rate'];
                $source['rates'][] = $record['rate'];
            }
        }
        
        return $source;
        
    }

    public function get_facility_rate_of_change_chart(array $dates, array $filter, array $dataset): ?array {
        $findBy = !empty(array_keys($filter)) ? array_keys($filter)[0] : null;
        $dataset['metaData']['field'][] = array('name' => 'date', 'type' => 'string');
        $dataset['metaData']['root'] = 'data';
        if ($findBy === 'fpl') {
            $data = $this->get_avg_tci_fpl($dates, $filter[$findBy]);
        }
        elseif ($findBy === 'facilityType') {
            $data = $this->get_avg_tci_facility_type($dates, $filter[$findBy]);
        }
        elseif ($findBy === 'facilityIds') {
            $facilityIds = json_decode($filter[$findBy]);
            $data = $this->get_averages_by_facilityIds($dates, $facilityIds);
        }
        if (!empty($data)) {
            $fields = [];
            foreach ($data as $key => $record) {
                $rate = $this->find_facility_rate_changes($record, $data);
                if ($rate !== null) { //Filter the empty 
                    $rates[] = $rate;
                    if (!in_array($record['facility'], array_column($fields, 'name'))){
                        $fields[] = array(
                            'name'      => $record['facility'],
                            'type'      => 'float',
                            'default'   => 0,
                        );
                    }  
                    $key = array_search($rate['date'], array_column($dataset['data'], 'date'));
                    if ($key !== false) {
                        $dataset['data'][$key][$rate['facility']] = $rate['rate'];
                        $dataset['rates'][] = $rate['rate'];
                    }
                }
            }
            $field = array_merge(array_filter($dataset['metaData']['fields'], function ($f) {
                return is_array($f);
            }), $fields);
      			$dataset['metaData']['store']['fields'] = array_merge($dataset['metaData']['field'], $field);
			      $dataset['metaData']['store']['proxy'] = $this->create_store_proxy_change_chart();
			      $dataset['metaData']['store']['remoteSort'] = false;
            $dataset['metaData']['fields'] = $dataset['metaData']['store']['fields'];
            $dataset['metaData']['max'] = max($dataset['rates']);
            $dataset['metaData']['min'] = min($dataset['rates']);
            $dataset['metaData']['median'] = $this->get_array_median($dataset['rates']);
            unset($dataset['rates']);
        }

        return $dataset;
        
    }

	public function get_national_rate_of_change(array $dates, array $filter): ?array {
        $natAvg = $this->get_national_averages($dates, $filter);
        $data = array();
        if (is_array(($natAvg)) && !empty($natAvg)) {
            foreach ($natAvg as $key => $recs) {
                $source[] = $this->find_national_rate_changes($recs, $natAvg);
            }
            $metaData = array_column($source, 'metaData');
            $facilityColumn = array(array(
                'flex'      => 1,
                'dataIndex' => 'facility',
                'text'      => 'Facility',
            ));
            $data['metaData'] = array(
                'fields'    => array_merge(array('facility'), array_column($metaData, 'field')),
                'columns'   => array_merge($facilityColumn, array_column($metaData, 'column')),
            );
            foreach (array_column($source, 'data') as $key => $record) {
                $data['data']['facility'] = empty($data['data']['facility']) ? $record['facility'] : $data['data']['facility']; 
                $data['data'][$record['date']] = $record['rate'];
            }

        }
        
        return $data;
    }

    public function updateSeries(array $fields): array{
        $series[] = array_map(function ($field) use ($fields) {
            array(
                'type'      => 'line',
                'title'     => $fields['facility'],
                'xField'    => $field,
            );
        }, $fields );

        return $series;
        
    }  

    /**
     * Retrieve the data for facility profiles report
     *
     * @param array $filter
     * @param array $sort
     * @return array
     */
    public function get_facility_profile_report(array $filter = NULL, array $sort = NULL): array {
        global $database;

        $facilityIds = $this->get_facility_profile_latest_effective_date($filter);
        $changes = $this->get_profile_changes(array_column($facilityIds, 'facility_profile_id'));
        if (!empty($changes)) {
            $ids = implode(',', array_keys($changes));
            $sql = 'SELECT `facility_profile_id`, `effective_date_start` AS effective_date, fp.`created_on` as request_date,
            `f`.`id` AS facility_code, `d`.`name` AS district,  ft.`name` AS facility_type, sa.`name` as service_area, fp.facility_id,
            IF (fp.rejected_on IS NOT NULL, fp.rejected_on, fp.approved_on) AS action_date,
            IF (fp.approved_on IS NULL AND fp.rejected_on IS NULL, "Open", IF (fp.approved_on > 0, "Approved", "Denied")) AS `status`,
            CONCAT( a.firstname, " " , a.lastname) AS requestor,
            CONCAT( rb.firstname, " " , rb.lastname) AS rejected_by,
            CONCAT( ab.firstname, " " , ab.lastname) AS approved_by
          FROM facility_profiles fp
          JOIN facilities f ON f.facility_id = fp.facility_id
          JOIN districts d ON f.district_id = d.district_id
          JOIN facility_types ft ON fp.facility_type_id = ft.facility_type_id
          JOIN service_areas sa ON d.service_area_id = sa.service_area_id
          LEFT JOIN accounts a ON a.account_id = fp.created_by
          LEFT JOIN accounts rb ON rb.account_id = fp.rejected_by
          LEFT JOIN accounts ab ON ab.account_id = fp.approved_by
          WHERE 1 = 1 
          AND facility_profile_id IN (' . $ids . ')
          ORDER BY effective_date_start DESC';

            $stmt = $database->prepare($sql);
            $stmt->execute();

            $facilityReport = $stmt->fetchAll();
            foreach ($facilityReport as $key => $row) {
                if (isset($changes[$row['facility_profile_id']])) {
                    $facilityReport[$key] = array_merge($row, $changes[$row['facility_profile_id']]);
                    $facilityReport[$key]['atc_level'] = $this->profile_changes_fpl($row['facility_id'], $row['effective_date']);
                }
            }
        } else {
            $facilityReport = [];
        }

        return $facilityReport;
    }

    public function get_tci_report(array $filter, array $sort): array
    {
        return $this->get_facility_tci($filter, $sort);
    }

    public function get_facility_types($filter = NULL, $sort = null): array
    {
        global $database;

        $sql = 'SELECT `facility_class_id`,`name` FROM `abacus`.`facility_classes` ';
        if ($filter !== null) {
            if (isset($filter['name'])) {
                $sql .= ' AND `name` LIKE :name ';
            }
        }
        $sql .= sprintf("\nORDER BY `%s` %s", $sort['sort'], $sort['dir']);
        if ($sort['limit'] > 0) {
            $sql .= sprintf("\nLIMIT %d, %d", $sort['start'], $sort['limit']);
        }
        $stmt = $database->prepare($sql);

        if ($filter !== null) {
            if (isset($filter["name"]) && strlen($filter["name"]) > 0) {
                $name = '%' . $filter['name'] . '%';
                $stmt->bindValue(':name', $name, PDO::PARAM_STR);
            }
        }
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }


    public function get_facility_tci(array $filter, array $sort = NULL): array {
        global $database;

        $sql = 'SELECT ft.`facility_tci_id`, `ft`.`facility_id`, `ft`.`date`, `fte`.`tci`, `ft`.`created_on`, 
        `ft`.`created_by`, `f`.`district_id`, `f`.`id` AS facility_code, `f`.`name` AS facility, `ftype`.`name` AS facility_class, 
        `d`.`service_area_id`, `d`.`name` AS district, `sa`.`name` AS service_area 
        FROM facility_tcis ft
        LEFT JOIN facility_tci_entries fte ON ft.facility_tci_id = fte.facility_tci_id
        LEFT JOIN facilities f ON f.facility_id = ft.facility_id 
        LEFT JOIN facility_types ftype ON ftype.facility_type_id = fte.facility_class_id
        LEFT JOIN districts d ON d.district_id = f.district_id
        LEFT JOIN service_areas sa ON sa.service_area_id = d.service_area_id 
        WHERE `date` BETWEEN :start_date AND :end_date ';


        if ($filter !== NULL) {
            if (
                !isset($filter['start_date']) || !isset($filter['end_date'])
                || empty($filter['start_date']) || empty($filter['end_date'])
            ) {
                $now = new DateTime();
                $filter['end_date'] = $now->format('Y-m-d');
                $filter['start_date'] = $now->modify('- 1 month')->format('Y-m-d');
            }
            if (isset($filter['facility_class_id']) && $filter['facility_class_id'] > 0) {
                $sql .= ' AND `ft`.`facility_class_id` = :facility_class_id ';
            }
            if (isset($filter['facility_code_id']) && strlen($filter['facility_code_id']) > 1) {
                $sql .= ' AND `f`.`id` = :facility_code_id ';
            }
            if (isset($filter['facility_tci_id']) && $filter['facility_tci_id'] > 1) { //query by id only
                $sql = substr($sql, 0, strpos($sql, 'WHERE ')) . ' WHERE `ft`.`facility_tci_id` = :facility_tci_id ';
            }
        }

        $sql .= sprintf("\nORDER BY `%s` %s, `date` DESC ", $sort['sort'], $sort['dir']);
        $sql .= $sort['limit'] > 0 ? sprintf("\nLIMIT %d, %d ", $sort['start'], $sort['limit']) : '';
        $stmt = $database->prepare($sql);
        if ($filter !== NULL) {
            if (stripos($sql, ':start') > 1) {
                $stmt->bindValue(':start_date', $filter['start_date'], PDO::PARAM_STR);
                $stmt->bindValue(':end_date', $filter['end_date'], PDO::PARAM_STR);
            }
            if (strpos($sql, ':facility_class_id') !== false) {
                $stmt->bindValue(':facility_class_id', $filter['facility_class_id'], PDO::PARAM_INT);
            }
            if (strpos($sql, ':facility_code') !== false) {
                $stmt->bindValue(':facility_code_id', $filter['facility_code_id'], PDO::PARAM_STR);
            }
            if (strpos($sql, ':facility_tci_id') !== false) {
                $stmt->bindValue(':facility_tci_id', $filter['facility_tci_id'], PDO::PARAM_INT);
            }
        }

        $stmt->execute();

        return $stmt->fetchall() ?: [];
    }

    public function get_facility_code_ids(array $filters = null): array {
        global $database;

        $sql = 'SELECT DISTINCT(f.id) AS facility_code_id,  ft.facility_id as facility_id, f.`name`
                FROM facility_tcis ft
                LEFT JOIN facilities f ON f.facility_id = ft.facility_id ORDER BY facility_code_id ASC';
        $stmt = $database->prepare($sql);
        $stmt->execute();

        return $stmt->fetchall() ?: [];
    }

    public function get_fpl_facility_code_ids(array $filters = null): array {
        global $database;

        $sql = 'SELECT DISTINCT(f.id) AS facility_code_id, ff.facility_id as facility_id, f.`name` as facility
                FROM facility_fpls ff
                LEFT JOIN facilities f ON f.facility_id = ff.facility_id ORDER BY facility_code_id ASC';
        $stmt = $database->prepare($sql);
        $stmt->execute();

        return $stmt->fetchall() ?: [];
    }

    public function get_fpl_facility_report($term = null, $tree = true): array {
        global $database;

        $sql = 'SELECT ff.id, f.facility_id, effective_date, fpl, f.id AS facility_code_id, f.name 
        FROM abacus.facility_fpls ff JOIN abacus.facilities f ON ff.facility_id = f.facility_id ';
        $sql  .= !empty($term) ? ' WHERE f.id LIKE :term || f.name LIKE :term ' : '';
        $sql .= 'ORDER BY f.id ASC, effective_date DESC;';

        $stmt = $database->prepare($sql);

        if (strpos($sql, ':term') !== false) {
            $term = '%' . $term . '%';
            $stmt->bindValue(':term', $term, PDO::PARAM_STR);
        }
        $stmt->execute();
        $data = array();
        $this->format_fpl_data($data, $stmt->fetchall(), $tree);

        return $data;
    }

    public function get_fpl_facility_excel_data($filter = []): array {
        return $this->get_fpl_facility_report(null, false);
    }

    public function get_facility_tci_trend(int $facility_id, array $dates = null, string $filter = null): ?array {
        global $facility_review;

        $data = $this->get_facility_tci_trend_data($facility_id, $dates);
        // $this->filter_trend_report('default', $data);
  
        return $data;
    }

    public function get_HoOA_report(int $facility_id, array $dates = null, array $filters): array{
        global $facilities, $database;
        $dates = empty($dates['start_date']) ? $this->get_default_dates('-1 year') : $dates;
        $profiles = $this->get_facility_profiles($facility_id, $dates);
        var_dump($profiles);
        die (var_dump($facilities->get_profile($facility_id)));

        return [];
    }

    public function get_facility_profiles(int $facility_id, array $dates) {
        global $database;

        $sql = 'SELECT facility_profile_id FROM `facilities` f 
                LEFT JOIN `facility_profiles` fp ON fp.facility_id = f.facility_id
                WHERE f.facility_id = :facilityId 
                AND effective_date_start BETWEEN :startDate AND :endDate ';
        $stmt = $database->prepare($sql);
        $stmt->bindValue(':facilityId', $facility_id, PDO::PARAM_INT);
        $stmt->bindValue(':startDate', $dates['start_date'], PDO::PARAM_STR);
        $stmt->bindValue(':endDate', $dates['end_date'], PDO::PARAM_STR);
        $stmt->execute();
    
        
        return $stmt->fetchAll();

    }

    public function tci_advance_report(int $tci_id, string $facility, string $facility_class, string $facility_code): array {
        $sort = array('sort' => 'facility_tci_id', 'dir' => 'DESC', 'limit' => null);
        $tci_data = $this->get_facility_tci(['facility_tci_id' => $tci_id], $sort)[0];
        $tci_data['tci_date'] = (new DateTime($tci_data['date']))->format('M Y');
        
        return ['body' => $this->tci_advance_report_body($tci_data)];
    }

    public function get_tci_report_excel_data(array $filter, array $sort): array {
        return $filter['start_date'] === false || $filter['end_date'] === false
            ? []
            : $this->get_facility_tci($filter, $sort);
    }

    private function format_fpl_data(array &$fpl, $rows, $tree = true) {
        if (!is_array($rows)) {
            return false;
        }
        $facility = '';
        foreach ($rows as $key => $row) { //Format data with current/prev FPL in one record
            $next_row = in_array($key + 1, array_keys($rows)) ? $rows[$key + 1] : ['facility_code_id' => null];
            $row['prev_fpl'] = $next_row['facility_code_id'] === $row['facility_code_id'] ? $next_row['fpl'] : 'none';
            $row['iconCls'] = 'fas fa-plane abacus-fa-sm-tree-icon fa-sm';
            $tree === true ? $this->facility_fpl_tree_source($row, $facility, $fpl) : $this->facility_fpl_export($row, $fpl);
        }
    }

    private function facility_fpl_tree_source($row, &$facility, &$fpl) {
        if ($facility !== $row['facility_code_id']) {
            $facility = $row['facility_code_id'];
            $row['data'] = array();
            $row['leaf'] = 'true';
            $fpl[] = $row;
        } else { //add it to the children of the last row
            $row['leaf'] = 'true';
            $index = count($fpl) - 1;
            if ($fpl[$index]['leaf'] === 'true') { //Has children remove 
                $fpl[$index]['leaf'] = false;
            }
            $fpl[$index]['data'][] = $row;
        }
    }

    private function facility_fpl_export(array $row, array &$fpl) {
        $fpl[] = $row;
    }

    private function tci_advance_report_body(array $data): string {
        $data['facility_class'] = strtolower($data['facility_class']);
        $styles = '        <style type="text/css">
      .tci-report-table {
      border: 2px double black;
      width: 100%;
      text-align: center;
      }
      .tci-report-table-header-row {
      background-color: #EEE;
      height: 2 em;
      text-align: center;
      font-weight: 700;
      font-size: 1.3 em;
      }
      .tci-report-table-header-row > td {
      padding: 10px;
      height: 2 em;
      text-align: center;
      font-weight: 700;
      font-size: 1.3 em;
      border: 2px solid black;
      }
      .tci-report-subheader {
      padding: 8px;
      }
      .tci-report-subheader > td {
      font-weight: 400;
      padding: 9px;
      font-size: 1.2 em;
      border: 1px solid black;
      }
      .tci-report-double-row {
        padding: 5px;
        border: 2px solid black;
      }
      .tci-report-section-header {
      font-size: 1.1 em;
      padding: 5px;
      }
      .tci-report-section-header > td {
      font-weight: bold;
      padding: 5px;
      }
      .tci-report-lead-column {
      font-weight: bold;
      }
      .tci-report-column-left {
      text-align: left;
      }
      .tci-report-data {
      padding: 2px;
      }
      .tci-report-data > td {
      border: 1px solid black;
      padding: 5px;
      }
      .tci-report-has-border {
      border: 1px solid black;
      padding: 5px;
      }
      .tci-report-step-command-cell {
      border-bottom: 1px solid gray;
      }
      .tci-report-step-description-cell {
      border-bottom: 1px solid gray;
      }
      .tci-report-step-result-cell-ok {
      border-bottom: 1px solid gray;
      background-color: silver;
      }
      .tci-report-step-result-cell-notperformed {
      border-bottom: 1px solid gray;
      background-color: white;
      }
      .tci-report-totals {
      border: none;
      background-color: pink;
      }
      .tci-report-describe-cell {
      background-color: tan;
      font-style: italic;
      }
    </style>';
        $template = [
            'approach' => '
        <h1 class="tci-reports-header">TCI Detailed Report {facility}</h1>
        <table class="tci-report-table" cellspacing="0" cellpadding="0">
          <thead>
            <tr class="tci-report-table-header-row">
              <td width="10%">{facility_code}</td>
              <td width="40%">Approach TCI Calculation</td>
              <td width="25%" colspan="2">Approach TCI</td>
              <td width="15%">{tci_date}</td>
              <td width="10%">{tci}</td>
            </tr>
          </thead>
          <tbody>
            <tr class="tci-report-subheader">
              <td class="tci-report-lead-column">Appendix A</td>
              <td colspan="4">&nbsp;</td>
              <td class="tci-report-step-result-cell-ok">1830 Details</td>
            </tr>
            <tr class="tci-report-section-header tci-report-data">
              <td>Part II</td>
              <td class="tci-report-column-left">Primary Airport Count</td>
              <td>Total Count</td>
              <td>Unweighted</td>
              <td>Weight</td>
              <td>Weighted</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">A</td>
              <td class="tci-report-column-left">IFR/SVFR/Practice Approach (PA) (Top 1830)</td>
              <td>{a_tc}</td>
              <td>{a_unwgt}</td>
              <td>{a_wgt}</td>
              <td>{a_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">B</td>
              <td class="tci-report-column-left">VFR itinerant/ARR/DEP (Top 1830)</td>
              <td>{b_tc}</td>
              <td>{b_unwgt}</td>
              <td>{b_wgt}</td>
              <td>{b_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">C</td>
              <td class="tci-report-column-left">VFR itinerant/ARR/DEP (Top 1830)</td>
              <td>{c_tc}</td>
              <td>{c_unwgt}</td>
              <td>{c_wgt}</td>
              <td>{c_wgted}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-lead-column tci-report-has-border">D</td>
              <td class="tci-report-has-border tci-report-column-left">Approach <strong>Runway Factors</strong></td>
              <td colspan="4">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left">Crossing Runways</td>
              <td>&nbsp;</td>
              <td>{d_cr_unwgted}</td>
              <td>{d_cr_wgt}</td>
              <td>{d_cr_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left"">Converging Runways</td>
              <td>&nbsp;</td>
              <td>{d_conr_unwgted}</td>
              <td>{d_conr_wgt}</td>
              <td>{d_conr_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left">Single Runways</td>
              <td>&nbsp;</td>
              <td>{d_sr_unwgted}</td>
              <td>{d_sr_wgt}</td>
              <td>{d_sr_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left">Parallel Runways</td>
              <td>&nbsp;</td>
              <td>{d_pr_unwgted}</td>
              <td>{d_pr_wgt}</td>
              <td>{d_pr_wgted}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>        
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">E</td>
              <td class="tci-report-column-left">IFR/SVFR ARR,DEP or PA (<=15 miles) (Top 1830)</td>
              <td>{e_lt_15_tc}</td>
              <td>{e_lt_15_unwgt}</td>
              <td>{e_lt_15_wgt}</td>
              <td>{e_lt_15_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">F</td>
              <td class="tci-report-column-left">IFR/SVFR ARR,DEP or PA (>15 miles) (Top 1830)</td>
              <td>{f_gt_15_tc}</td>
              <td>{f_gt_15_unwgt}</td>
              <td>{f_gt_15_wgt}</td>
              <td>{f_gt_15_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">G</td>
              <td class="tci-report-column-left">VFR ARR/DEP/Overflight (<=15 & >15 & VFR Over) (Top 1830)</td>
              <td>{g_gte_15_tc}</td>
              <td>{g_gte_15_unwgt}</td>
              <td>{g_gte_15_wgt}</td>
              <td>{g_gte_15_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">H</td>
              <td class="tci-report-column-left">IFR/SVFR overflights (Top 1830)</td>
              <td>{h_tc}</td>
              <td>{h_unwgt}</td>
              <td>{h_wgt}</td>
              <td>{h_wgted}</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">I</td>
              <td class="tci-report-column-left tci-report-has-border" colspan="4">
                <h3>Average Weighted Hourly (Count Sum D thru H)</h3>
              </td>
              <td class="tci-report-totals">
                <h3>{gh_totals}</h3>
              </td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-has-border tci-report-lead-column">J</td>
              <td class="tci-report-column-left tci-report-has-border">
                <h3>Military Add-on count</h3>
              </td>
              <td>{military_count}</td>
              <td>{military_unwgted}</td>
              <td>{military_wgt}</td>
              <td class="tci-report-totals">
                <h3>{military_totals}</h3>
              </td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">K</td>
              <td class="tci-report-column-left tci-report-has-border">
                <h3>Aircraft Mix Calculation</h3>
              </td>
              <td class="tci-report-has-border">Total Count</td>
              <td colspan="3">&nbsp;</td>
            </tr>
            <tr>    
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Air Carrier and Military combined</td>
              <td class="tci-report-has-border">{k_aircarrier_plus_military}</td>
              <td class="tci-report-has-border">{k_am_unwgted}%</td>
              <td class="tci-report-has-border">{k_wgted}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">General Aviation</td>
              <td class="tci-report-has-border">{k_general_aviation}</td>
              <td class="tci-report-has-border">{k_ga_unwgted}%</td>
              <td class="tci-report-has-border">{k_ga_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Air Taxi</td>
              <td class="tci-report-has-border">{k_general_aviation}</td>
              <td class="tci-report-has-border">{k_at_unwgted}%</td>
              <td class="tci-report-has-border">{k_at_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">K 2-4</td>
              <td class="tci-report-column-left tci-report-has-border ">Mix with Air Taxi</td>
              <td class="tci-report-has-border">{k_mix_air_taxi}</td>
              <td class="tci-report-has-border">{k_mat_unwgted}%</td>
              <td class="tci-report-has-border">{k_mat_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Mix without Air Taxi</td>
              <td class="tci-report-has-border">{k_mix_air_taxi}</td>
              <td class="tci-report-has-border">{k_mix_unwgted}%</td>
              <td class="tci-report-has-border">{k_mix_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">K 5</td>
              <td class="tci-report-column-left tci-report-has-border " colspan="4">
                <h3>Mix of Traffic Count</h3>
              </td>
              <td class="tci-report-has-border tci-report-totals">{k_totals}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">L</td>
              <td class="tci-report-column-left" colspan="4">Approach Pro file <strong>Add-ons</strong></td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">L1</td>
              <td class="tci-report-column-left tci-report-has-border">Class B Airspace</td>
              <td class="tci-report-has-border"></td>
              <td class="tci-report-has-border">{L1_classb_unwgted}</td>
              <td class="tci-report-has-border">{L1_classb_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Class C Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l1_classc_unwgted}</td>
              <td class="tci-report-has-border">{l1_classb_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">TRSA Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l1_ta_unwgted}</td>
              <td class="tci-report-has-border">{l1_ta_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Class D Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l1_classd_unwgted}</td>
              <td class="tci-report-has-border">{l1_classd_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">L2</td>
              <td class="tci-report-column-left tci-report-has-border ">Mountainous Terrain</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l2_mt_unwgted}</td>
              <td class="tci-report-has-border">{l2_mt_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">L3</td>
              <td class="tci-report-column-left tci-report-has-border ">Foreign Interraction</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l3_fi_unwgted}</td>
              <td class="tci-report-has-border">{l3_fi_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border"><h3>Total Facility Profile Count</h3></td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l_total_facility_count_percent}%</td>
              <td class="tci-report-totals">{l_total_count}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">M</td>
              <td class="tci-report-column-left tci-report-lead-column">Non-Radar Add-on</td>
              <td>Total Ops Year</td>
              <td>Total Non-Radar Ops</td>
              <td>Calculated Multiplier</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
                <td></td>
                <td></td>
                <td>{m_TOY}</td>
                <td>{m_TNRO}</td>
                <td>{m_CM}%</td>
                <td class="tci-report-totals">{m_total_count}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
                <td class="tci-report-lead-column">N-WAV1</td>
                <td colspan="4" class="tci-report-column-left tci-report-lead-column">Modified Average Weighted Hourly Count (Sum I thru M)</td>
                <td class="tci-report-totals">{n_total_count}</td>
            </tr>
            <tr>
                <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">O</td>
              <td class="tci-report-has-border tci-report-column-left tci-report-lead-column">Traffic Count Index Calculation</td>
              <td class="tci-report-has-border">Total Count</td>
              <td colspan="3">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">Cav 1</td>
              <td class="tci-report-has-border tci-report-column-left">Average unweighted hourly count busiest 1830</td>
              <td class="tci-report-has-border">{cav1_total_count}</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{cav1_average}</td>
              <td colspan="">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">Cav2</td>
              <td class="tci-report-has-border tci-report-column-left">Average unweighted hourly count second busiest 1830</td>
              <td class="tci-report-has-border">{cav2_total_count}</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{cav2_average}</td>
              <td colspan="">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">Dt</td>
              <td colspan="4" class="tci-report-has-border tci-report-lead-column tci-report-column-left">Sustained Traffic Count Index [ Dt = 1+ (Cav2 / Cav1) ]</td>
              <td class="tci-report-totals">{STCI_total}</td>
            </tr>
            <tr>
                <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-double-row">
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td colspan="3" class="tci-report-has-border tci-report-lead-column tci-report-column-left">Approach <strong>Traffic Count Index [ Dt x Wav1 ]</strong></td>
              <td class="tci-report-has-border">{run_date}</td>
              <td class="tci-report-totals">{Approach_total}</td>
            </tr>
            </tbody>
        </table>
        ', 'tower' =>
            '
        <h1 class="tci-reports-header">TCI Detailed Report {facility}</h1>
        <table class="tci-report-table" cellspacing="0" cellpadding="0">
          <thead>
            <tr class="tci-report-table-header-row">
              <td width="10%">{facility_code}</td>
              <td width="40%">Tower TCI Calculation</td>
              <td width="25%" colspan="2">Tower TCI</td>
              <td width="15%">{tci_date}</td>
              <td width="10%">{tci}</td>
            </tr>
          </thead>
          <tbody>
            <tr class="tci-report-subheader">
              <td class="tci-report-lead-column">Appendix A</td>
              <td colspan="4">&nbsp;</td>
              <td class="tci-report-step-result-cell-ok">1830 Details</td>
            </tr>
            <tr class="tci-report-section-header tci-report-data">
              <td>Part III</td>
              <td class="tci-report-column-left">Primary Airport Count</td>
              <td>Total Count</td>
              <td>Unweighted</td>
              <td>Weight</td>
              <td>Weighted</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">A</td>
              <td class="tci-report-column-left">IFR/SVFR/Practice Approach (PA) (Top 1830)</td>
              <td>{a_total_count}</td>
              <td>{a_unwgted}</td>
              <td>{a_wgt}</td>
              <td>{a_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">B</td>
              <td class="tci-report-column-left">VFR itinerant/ARR/DEP (Top 1830)</td>
              <td>{b_total_count}</td>
              <td>{b_unwgted}</td>
              <td>{b_wgt}</td>
              <td>{b_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">C</td>
              <td colspan="4" class="tci-report-column-left">VFR itinerant/ARR/DEP (Top 1830)</td>
              <td>{c_wgted}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>        
            <tr>
              <td class="tci-report-lead-column tci-report-has-border">D</td>
              <td class="tci-report-has-border">Approach <strong>Runway Factors</strong></td>
              <td colspan="4">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left">Crossing Runways</td>
              <td>&nbsp;</td>
              <td>{d_cr_unwgted}</td>
              <td>{d_cr_wgt}</td>
              <td>{d_cr_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left"">Converging Runways</td>
              <td>&nbsp;</td>
              <td>{d_conr_unwgted}</td>
              <td>{d_conr_wgt}</td>
              <td>{d_conr_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left">Single Runways</td>
              <td>&nbsp;</td>
              <td>{d_sr_unwgted}</td>
              <td>{d_sr_wgt}</td>
              <td>{d_sr_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left">Parallel Runways</td>
              <td>&nbsp;</td>
              <td>{d_pr_unwgted}</td>
              <td>{d_pr_wgt}</td>
              <td>{d_pr_wgted}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">E</td>
              <td class="tci-report-column-left">IFR/SVFR overflights (Top 1830)</td>
              <td>{e_tc}</td>
              <td>{e_unwgt}</td>
              <td>{e_wgt}</td>
              <td>{e_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">F</td>
              <td class="tci-report-column-left">VFR overflights (Top 1830)</td>
              <td>{f_tc}</td>
              <td>{f_unwgt}</td>
              <td>{f_wgt}</td>
              <td>{f_wgted}</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">G</td>
              <td colspan="4" class="tci-report-lead-column tci-report-column-left">Average Weighted Hourly Count (Sum D thru F)</td>
              <td class="tci-report-totals">{g_wgted}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">H</td>
              <td class="tci-report-column-left"><h3>Military Add-on count</h3></td>
              <td>{h_tc}</td>
              <td>{h_unwgt}</td>
              <td>{h_wgt}</td>
              <td>{h_wgted}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">I</td>
              <td class="tci-report-column-left tci-report-has-border"><h3>Aircraft Mix Calculation</h3></td>
              <td class="tci-report-has-border">Total Count</td>
              <td colspan="3">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Air Carrier and Military combined</td>
              <td class="tci-report-has-border">{i_ac_and_mc_tc}</td>
              <td class="tci-report-has-border">{i_ac_mc}%</td>
              <td colspan="2">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">General Aviation</td>
              <td class="tci-report-has-border">{i_ga_tc}</td>
              <td class="tci-report-has-border">{i_ga}%</td>
              <td colspan="2">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Air Taxi</td>
              <td class="tci-report-has-border">{i_at_tc}</td>
              <td class="tci-report-has-border">{i_at}%</td>
              <td colspan="2">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">I 2-4</td>
              <td class="tci-report-column-left tci-report-has-border">Mix with Air Taxi</td>
              <td class="tci-report-has-border">{i_mix_at_tc}</td>
              <td class="tci-report-has-border">{i_mix_at}%</td>
              <td colspan="2">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Mix without Air Taxi</td>
              <td class="tci-report-has-border">{i_mix_at_tc}</td>
              <td class="tci-report-has-border">{i_mix_at}%</td>
              <td colspan="2">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">I 5</td>
              <td colspan="4" class="tci-report-column-left"><h3>Mix of Traffic Count</h3></td>
              <td class="tci-report-total">{i_5_tc}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">J</td>
              <td colspan="4" class="tci-report-column-left tci-report-has-border"><h3>Tower Profile Add-ons</h3></td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">J1</td>
              <td class="tci-report-column-left tci-report-has-border">Class B Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{j1_cb_unwgted}</td>
              <td class="tci-report-has-border">{j1_cb_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Class C Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{j1_cc_unwgted}</td>
              <td class="tci-report-has-border">{j1_cc_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">TRSA Airspace</td>
              <td class="tci-report-column-left tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">{j1_ta_unwgted}</td>
              <td class="tci-report-column-left tci-report-has-border">{j1_ta_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Class D Airspace</td>
              <td class="tci-report-column-left tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">{j1_cd_unwgted}</td>
              <td class="tci-report-column-left tci-report-has-border">{j1_cd_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">J2</td>
              <td class="tci-report-column-left tci-report-has-border">ASOS</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{j2_unwgted}</td>
              <td class="tci-report-has-border">{j2_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">J4</td>
              <td class="tci-report-column-left tci-report-has-border">Mountainous Terrain</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{j4_unwgted}</td>
              <td class="tci-report-has-border">{j4_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">J5</td>
              <td class="tci-report-column-left tci-report-has-border">Foreign Interraction</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{j5_unwgted}</td>
              <td class="tci-report-has-border">{j5_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">J6</td>
              <td class="tci-report-column-left tci-report-has-border">Foreign Interraction</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{j6_unwgted}</td>
              <td class="tci-report-has-border">{j6_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td colspan="3" class="tci-report-column-left tci-report-has-border"><h3>Total Facility Profile Count</h3></td>
              <td class="tci-report-has-border">{total_facility_profile_count_wgt}</td>
              <td class="tci-report-totals">{total_facility_profile_count}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-has-border tci-report-lead-column">K - WAV1</td>
              <td class="tci-report-column-left tci-report-has-border" colspan="4">
                <h3>Modified Average Weighted Hourly Count (Sum I thru J)</h3>
              </td>
              <td class="tci-report-totals">{mawhc_total}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">L</td>
              <td class="tci-report-column-left tci-report-has-border"><h3>Traffic Count Index Calculation</h3></td>
              <td class="tci-report-has-border">Total Count</td>
              <td class="tci-report-has-border" colspan="2">&nbsp;</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">Cav 1</td>
              <td class="tci-report-column-left tci-report-has-border">Average unweighted hourly count busiest 1830</td>
              <td class="tci-report-has-border">{l_auhcb_tc}</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l_auhcb_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">Cav2</td>
              <td class="tci-report-column-left tci-report-has-border">Average unweighted hourly count second busiest 1830</td>
              <td class="tci-report-has-border">{l_auhcsb_tc}</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l_auhcsb_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">Dt</td>
              <td class="tci-report-column-left tci-report-has-border" colspan="4"><h3>Sustained Traffic Count Index [ Dt = 1+ (Cav2 / Cav1) ]</h3></td>
              <td class="tci-report-totals">{stcin_tc}</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Air Taxi</td>
              <td class="tci-report-has-border">{k_general_aviation}</td>
              <td class="tci-report-has-border">{k_at_unwgted}%</td>
              <td class="tci-report-has-border">{k_at_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">K 2-4</td>
              <td class="tci-report-column-left tci-report-has-border ">Mix with Air Taxi</td>
              <td class="tci-report-has-border">{k_mix_air_taxi}</td>
              <td class="tci-report-has-border">{k_mat_unwgted}%</td>
              <td class="tci-report-has-border">{k_mat_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Mix without Air Taxi</td>
              <td class="tci-report-has-border">{k_mix_air_taxi}</td>
              <td class="tci-report-has-border">{k_mix_unwgted}%</td>
              <td class="tci-report-has-border">{k_mix_wgt}</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">K 5</td>
              <td class="tci-report-column-left tci-report-has-border " colspan="4">
                <h3>Mix of Traffic Count</h3>
              </td>
              <td class="tci-report-has-border tci-report-totals">{k_totals}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">L</td>
              <td class="tci-report-column-left" colspan="4">Approach Pro file <strong>Add-ons</strong></td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">L1</td>
              <td class="tci-report-column-left tci-report-has-border">Class B Airspace</td>
              <td class="tci-report-has-border"></td>
              <td class="tci-report-has-border">{L1_classb_unwgted}</td>
              <td class="tci-report-has-border">{L1_classb_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Class C Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l1_classc_unwgted}</td>
              <td class="tci-report-has-border">{l1_classb_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">TRSA Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l1_ta_unwgted}</td>
              <td class="tci-report-has-border">{l1_ta_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border">Class D Airspace</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l1_classd_unwgted}</td>
              <td class="tci-report-has-border">{l1_classd_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">L2</td>
              <td class="tci-report-column-left tci-report-has-border ">Mountainous Terrain</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l2_mt_unwgted}</td>
              <td class="tci-report-has-border">{l2_mt_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border tci-report-lead-column">L3</td>
              <td class="tci-report-column-left tci-report-has-border ">Foreign Interraction</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l3_fi_unwgted}</td>
              <td class="tci-report-has-border">{l3_fi_wgt}%</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="">
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-column-left tci-report-has-border"><h3>Total Facility Profile Count</h3></td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{l_total_facility_count_percent}%</td>
              <td class="tci-report-totals">{l_total_count}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td class="tci-report-lead-column">M</td>
              <td class="tci-report-column-left tci-report-lead-column">Non-Radar Add-on</td>
              <td>Total Ops Year</td>
              <td>Total Non-Radar Ops</td>
              <td>Calculated Multiplier</td>
              <td>&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
                <td></td>
                <td></td>
                <td>{m_TOY}</td>
                <td>{m_TNRO}</td>
                <td>{m_CM}%</td>
                <td class="tci-report-totals">{m_total_count}</td>
            </tr>
            <tr>
              <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
                <td class="tci-report-lead-column">N-WAV1</td>
                <td colspan="4" class="tci-report-column-left tci-report-lead-column">Modified Average Weighted Hourly Count (Sum I thru M)</td>
                <td class="tci-report-totals">{n_total_count}</td>
            </tr>
            <tr>
                <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">O</td>
              <td class="tci-report-has-border tci-report-column-left tci-report-lead-column">Traffic Count Index Calculation</td>
              <td class="tci-report-has-border">Total Count</td>
              <td colspan="3">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">Cav 1</td>
              <td class="tci-report-has-border tci-report-column-left">Average unweighted hourly count busiest 1830</td>
              <td class="tci-report-has-border">{cav1_total_count}</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{cav1_average}</td>
              <td colspan="">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">Cav2</td>
              <td class="tci-report-has-border tci-report-column-left">Average unweighted hourly count second busiest 1830</td>
              <td class="tci-report-has-border">{cav2_total_count}</td>
              <td class="tci-report-has-border">&nbsp;</td>
              <td class="tci-report-has-border">{cav2_average}</td>
              <td colspan="">&nbsp;</td>
            </tr>
            <tr>
              <td class="tci-report-has-border tci-report-lead-column">Dt</td>
              <td colspan="4" class="tci-report-has-border tci-report-lead-column tci-report-column-left">Sustained Traffic Count Index [ Dt = 1+ (Cav2 / Cav1) ]</td>
              <td class="tci-report-totals">{STCI_total}</td>
            </tr>
            <tr>
                <td colspan="6" class="tci-report-no-border tci-report-double-row">&nbsp;</td>
            </tr>
            <tr class="tci-report-data">
              <td>&nbsp;</td>
              <td class="tci-report-column-left" colspan="3"><h3>Tower Traffic Count Index [ Dt x Wav1 ]</h3></td>
              <td>{tci_date}</td>
              <td class="tci-report-totals">{tci}</td>
            </tr>
            </tbody>
        </table>',
            'en route' => ''
        ];
        $body = $template[$data['facility_class']];

        foreach ($data as $key => $item) {
            $body = str_replace('{' . $key . '}', $item, $body);
        }

        return $styles . $body;
    }

    private function get_profile_changes(array $facilityIds): ?array {
        global $facilities;

        $new = null;
        foreach ($facilityIds as $id) {
            $changes = $facilities->get_profile_for_changes($id);
            if (!empty($changes['comments']) || !empty($changes['changes'])) {
                $new[$id] = $this->format_profile_changes($changes);
            }
        }

        return $new;
    }

    private function format_profile_changes(array $changes): ?array {
        $ret = [
            'attribute'   => '',
            'comments'    => $changes['comments'],
            'change_from' => '',
            'change_to'   => '',
        ];

        if (!empty($changes['changes'])) {

            array_walk($changes['changes'], array($this, 'profile_changes_conversions'));

            $ret['change_from'] = implode('<br />', (array_column($changes['changes'], 'value_old')));
            $ret['change_to'] = implode('<br />', array_column($changes['changes'], 'value_new'));
            $ret['attribute'] = implode('<br />', array_column($changes['changes'], 'field'));
        }

        return $ret;
    }

    private function profile_changes_conversions(&$val, $key)  {
        $binary = [
            'Tower ASOS',
            'Tower LAWRS',
            'Tower Mountainous Terrain',
            'Tracon Non-Radar Facility',
            'Tracon Mountainous Terrain',
            'En Route Mountainous Terrain',
            'DST',
        ];
        if (in_array($val['field'], $binary)) {
            $val['value_old'] = (int) $val['value_old'] === 0 || is_null($val['value_old']) ? 'No' : 'Yes';
            $val['value_new'] = (int) $val['value_new'] === 0 || is_null($val['value_new']) ? 'No' : 'Yes';
        }
    }

    private function profile_changes_fpl(int $facility_id, string $effective_date): ?int {
        global $database;

        $sql = 'SELECT fpl
        FROM facility_fpls
        WHERE facility_id=:facilityId
        AND effective_date<= :effective_date
        ORDER BY `effective_date` DESC,`id` DESC
        LIMIT 1 ';

        $stmt = $database->prepare($sql);
        $stmt->bindValue(':facilityId', $facility_id, PDO::PARAM_INT);
        $stmt->bindValue(':effective_date', $effective_date, PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetchAll();

        return isset($result[0]['fpl']) ? $result[0]['fpl'] : null;
    }

/**************************
 * Calculations 
 */

    private function map_spectrum_average(array &$tci, int $index, array $tciAvg): void {
        $indexes = array_keys(array_column($tciAvg, 'facility'), $tci['facility'], true);
        if (is_array($indexes) && !empty($indexes)) {
            $avgMatch = $this->get_spectrum_tci_match($indexes, $tciAvg, $tci);
            $tci['direction'] = $tci['tci'] > $avgMatch['avg_tci'] ? 'up' : 'down';
            $tci['tci_average'] = $avgMatch['avg_tci'];
        }

    }

    private function get_spectrum_tci_match(array $indexes, array $tciAvg, array $tci ): ?array {
        foreach ($indexes as $index) {
            if ($tciAvg[$index]['fc_id'] === $tci['facility_class_id'] ){
                return $tciAvg[$index];
            }
        }
        
        return null;
    }

    private function get_fpl_breakpoint_avg(array &$tciAvgRecord, $index, array $breakpoints): void {
        global $database;

        $facilityId = $tciAvgRecord['facility_id'];
        $fpl_sql = 'SELECT `fpl`
                FROM `facility_fpls`
                WHERE `facility_id` = :facilityId AND `effective_date` <= CURDATE()
                ORDER BY `effective_date` DESC LIMIT 1';
        $stmt = $database->prepare($fpl_sql);
        $stmt->bindValue(':facilityId', $facilityId, PDO::PARAM_INT);
        $stmt->execute();
        $records = $stmt->fetchall();
        $fpl = $records[0]['fpl'] ?? false;
        $key = array_search($fpl, array_column($breakpoints, 'fpl'));
        if ($key !== false) {
            $breakpoint = $breakpoints[$key];
            $breakpointPlacements = $this->breakpoint_spectrum_placement($breakpoint, $tciAvgRecord['tci']);
            $tciAvgRecord = array_merge($tciAvgRecord,
                array(
                    'col' => $breakpointPlacements['col'],
                    'align' => $breakpointPlacements['align'],
                    'percent' => $breakpointPlacements['percentage'],
                    'fpl' => $breakpoint['fpl'],
                    'min' => $breakpoint['min'],
                    'max' => $breakpoint['max'],
                    'fields' => $breakpointPlacements['fields'],
                )
            );
        }

    }

    private function spectrum_breakpoints_vertical($rec, $breakpoints): array {
        $key = array_search($rec['fpl'], array_column($breakpoints, 'fpl'));
        if ($key !== false) {
            $breakpoint = $breakpoints[$key];
            $breakpointPlacements = $this->breakpoint_spectrum_placement($breakpoint, $rec['tci']);
            return array_merge(
                $rec,
                array(
                    'col' => $breakpointPlacements['col'],
                    'align' => $breakpointPlacements['align'],
                    'percent' => $breakpointPlacements['percentage'],
                    'fpl' => $breakpoint['fpl'],
                    'min' => $breakpoint['min'],
                    'max' => $breakpoint['max'],
                    'buffer' => $breakpoint['buffer'],
                    'fields' => $breakpointPlacements['fields'],
                )
            );
        }

        return [];
    }

    private function get_tci_spectrum_values(string $date, array $filters): array {
        global $database;
        $report = array();

        list($year, $month, $day) = explode('-', $date);
        $sql = 'SELECT f.id AS facility, facility_id, fte.tci, facility_class_id, facility_tci_entry_id, fpl, ft.DATE, ftype.name AS facility_type
                FROM facility_tcis ft 
                JOIN facility_tci_entries fte USING (facility_tci_id)
                JOIN facility_fpls ff USING (facility_id)
                JOIN facility_type_classes ftc USING (facility_class_id)
                JOIN facility_types ftype  USING (facility_type_id)
                JOIN facilities f USING (facility_id)
                WHERE ff.id  = (SELECT id FROM facility_fpls fpls WHERE fpls.facility_id = ft.facility_id ORDER BY effective_date DESC LIMIT 1)
                AND YEAR(ft.DATE) = :year AND MONTH(ft.DATE) = :month';
        $sql .= isset($filters['facilityType']) && $filters['facilityType'] !== null ? ' AND facility_type_id = :facilityType' : ''; 
        $sql .= isset($filters['fpl']) && $filters['fpl'] !== null ? ' AND fpl = :fpl' : '';
        if (isset($filters['facilityIds']) && is_array($filters['facilityIds'])) {
            $sql = str_replace(array(':year', ':month'), '?', $sql);
            $questionMarks = rtrim(str_repeat('?, ', count($filters['facilityIds'])), ', ');
            $sql .= ' AND ft.facility_id IN ( '. $questionMarks . ')';
        }
        $stmt = $database->prepare($sql);
        if (isset($filters['facilityIds'])) {
            $bindValues = array_merge(array($year, $month), $filters['facilityIds']);
            foreach ($bindValues as $index => $value) {
                $stmt->bindValue($index + 1, $value, PDO::PARAM_INT);
            }
        }
        else {
            $stmt->bindValue(':year', $year, PDO::PARAM_INT);
            $stmt->bindValue(':month', $month, PDO::PARAM_INT);
        }
        if (strpos($sql, ':facilityType') !== false) {
            $stmt->bindValue(':facilityType', $filters['facilityType'],PDO::PARAM_INT);
        }
        if (strpos($sql, ':fpl') !== false) {
            $stmt->bindValue(':fpl', $filters['fpl'],PDO::PARAM_INT);
        }
        $stmt->execute();
        $records = $stmt->fetchAll();
        if ($records !== false && !empty($records)) {
            $report = $this->filter_match_selected_spectrum_records($records);
        }

        return $report; 

    }

    private function filter_match_selected_spectrum_records(array $records): array {
        global $breakpoints;

        $breakpoint = $breakpoints->get_breakpoints_min_buffet_max();
        $report = array();
        foreach ($records as $key => $record) {
            $selectedRecords = isset($report[$record['facility_id']][$record['facility_class_id']]);
            if ($selectedRecords === false) {
                $report[$record['facility_id']][$record['facility_class_id']] = $this->spectrum_breakpoints_vertical($record, $breakpoint);
            } elseif ($selectedRecords === true && $selectedRecords['facility_tci_entry_id'] < $record['facility_tci_entry_id']) { //replace Record w/ latest
                $report[$record['facility_id']][$record['facility_class_id']] = $this->spectrum_breakpoints_vertical($record, $breakpoint);
            }
        }

        return call_user_func_array('array_merge', $report);
        
    }

    private function breakpoint_spectrum_placement(array $breakpoint, float $tci): ?array {
        $fields = array(
            'max'       => '&nbsp;',
            'breakpoint'=> '&nbsp;',
            'min'       => '&nbsp;',
            'buffer'    => '&nbsp;',
        );
        $per = array();
        $breakpoint['fpl'] = intval($breakpoint['fpl']);
        if ($breakpoint['fpl'] === 4) {
            if ($tci > $breakpoint['max']) {
                $col = 'max';
                $percentage = ($breakpoint['max']/$tci *100);
                $fields['max'] = 'bullseye';
            } else {
                $col = 'breakpoint';
                $percentage = ($breakpoint['max'] - $tci)/$breakpoint['max'] * 100;
                $fields['breakpoint'] = 'bullseye';
            }
        } else { //FPL 4 min is 0 so can only have max and breakpoint everything else process here
            switch (true) {
                case ($tci > $breakpoint['max']):
                    $col = 'max';
                    $percentage = ($breakpoint['max']/$tci * 100);
                    $fields['max'] = 'bullseye';
                    break;
                case ($tci <= $breakpoint['max'] && $tci > $breakpoint['min']):
                    $col = 'breakpoint';
                    $percentage = (($breakpoint['max'] - $breakpoint['min'])/$tci * 100);
                    $fields['breakpoint'] = 'bullseye';
                    break;
                case ($tci <= $breakpoint['min'] && $tci > $breakpoint['buffer']):
                    $col = 'min';
                    $percentage =  (($breakpoint['min'] - $breakpoint['buffer']) / $tci * 100);
                    $fields['min'] = 'bullseye';
                    break;
                
                default:
                    $col = 'buffer';
                    $percentage =  (abs($tci/($breakpoint['max'] * .93) * 100));
                    $fields['buffer'] = 'bullseye';
                    break;                

            }

        }

        return array(
            'col' => $col,
            'percentage' => $percentage,
            'per_from_' . $col => 100 - $percentage,
            'align' => $this->get_spectrum_breakpoint_alignments($percentage),
            'fields' => $fields,
        );
    }

    private function get_spectrum_breakpoint_alignments(float $percentage): string {
        return $percentage > 66 ? 'right' : ($percentage < 66 && $percentage > 33 ? 'center' : 'left');
    }

    public function get_facility_tci_trend_data(int $facility_id = 51, array $dates = null): ?array {
        global $database;

        $useDates = $dates['end_date']->format('Y-m-d') === date('Y-m-d');
        $tci_sql = 'SELECT `tc`.`facility_tci_id`, `tc`.`facility_id`, ROUND(SUM(`tc`.`tci`),2) AS `tci`, 
                        DATE_FORMAT(`date`,\'%Y-%m\') AS `date`, ABS(PERIOD_DIFF(DATE_FORMAT(`tc`.`date`, \'%Y%m\'), 
                        DATE_FORMAT(FROM_UNIXTIME(UNIX_TIMESTAMP()), \'%Y%m\'))) AS `month_diff`
                    FROM (SELECT MAX(`facility_tci_id`) AS `facility_tci_id` FROM `facility_tcis` `fte` WHERE `facility_id` = :facilityId GROUP BY DATE_FORMAT(`date`,\'%Y-%m\')) `max_tc`
                    INNER JOIN
                        (SELECT `tc`.*, `fte`.`tci`
                            FROM `facility_tcis` `tc`
                            LEFT JOIN `facility_tci_entries` `fte`
                        ON `tc`.`facility_tci_id` = `fte`.`facility_tci_id`
                        WHERE `facility_id` = :facilityId) `tc`
                        ON `max_tc`.`facility_tci_id` = `tc`.`facility_tci_id`';
        $tci_sql .= $useDates === false ? ' AND `date` BETWEEN :start_date AND :end_date ' : '';
        $tci_sql .= ' GROUP BY `tc`.`facility_tci_id`
                    ORDER BY `tc`.`date` DESC';
        $tci_sql .= $useDates === true ? ' LIMIT :duration' : '';                    
        
        $stmt = $database->prepare($tci_sql);    
        $stmt->bindValue(':facilityId', $facility_id, PDO::PARAM_INT);
        if (strpos($tci_sql, ':duration') !== false) {
            $stmt->bindValue(':duration', $dates['duration'], PDO::PARAM_INT);
        }
        if (strpos($tci_sql, ':start_date') !== false) {
            $stmt->bindValue(':start_date', $dates['start_date']->format('Y-m-d'), PDO::PARAM_INT);
            $stmt->bindValue(':end_date', $dates['end_date']->format('Y-m-d'), PDO::PARAM_INT);
        }
        $stmt->execute();
        $tcis = $stmt->fetchAll();
        if (empty($tcis)) {
            return [];
        }
        array_walk($tcis, array($this, 'add_facility_class_totals'));

        //Get current fpl
        $sql = 'SELECT `fpl`
                FROM `facility_fpls`
                WHERE `facility_id` = :facilityId AND `effective_date` <= CURDATE()
                ORDER BY `effective_date` DESC LIMIT 1';
        $stmt = $database->prepare($sql);
        $stmt->bindValue(':facilityId', $facility_id, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetch();
        $fpl = $results !== false && count($results) > 0 ? intval($results["fpl"]) : 0;
        //Get buffer/min/max for a facility
        $sql =  'SELECT `be`.*, FLOOR(`be`.`min`*(100-`be`.`percent`)/100) AS `buffer`, `bp`.`facility_type_id`
                FROM `breakpoint_entries` `be`
                INNER JOIN
                    (SELECT `a`.*
                        FROM `breakpoints` `a`
                        INNER JOIN 
                            (SELECT `facility_type_id`, `effective_date`, `breakpoint_id`
                                FROM `breakpoints`
                                WHERE `effective_date` <= CURDATE()
                                ORDER BY `effective_date` DESC, `breakpoint_id` DESC) `b` ON  `a`.`breakpoint_id` = `b`.`breakpoint_id`
                            INNER JOIN
                                (SELECT `fp`.*
                                    FROM `facility_profiles` `fp`
                                    WHERE `facility_id` = :facilityId AND `effective_date_start` <= CURDATE()
                                    ORDER BY `effective_date_start` DESC
                                    LIMIT 1) `fp` ON `fp`.`facility_type_id` = `a`.`facility_type_id`
                                    ORDER BY `a`.`effective_date` DESC, `a`.`breakpoint_id` DESC LIMIT 1
                                ) `bp` ON `be`.breakpoint_id = `bp`.`breakpoint_id`
                GROUP BY `be`.`breakpoint_entry_id`
                ORDER BY `be`.`fpl` DESC';
        $stmt = $database->prepare($sql);
        $stmt->bindValue(':facilityId', $facility_id, PDO::PARAM_INT);
        $stmt->execute();
        $buffer_min_max = $stmt->fetchall();
        
        $fpl_data = $this->get_fpl_ranges($buffer_min_max, $fpl);
        $fpl_data['fpl'] = $fpl;

        foreach ($tcis as &$tci) {
            $tci = array_merge($tci, $fpl_data);
        }

        return array_reverse($tcis);
    }

    private function get_fpl_ranges(array $max_mins, int $fpl): array {
        $ret = [
          'min_level_fpl' => $fpl - 1,
          'max_level_fpl' => $fpl + 1,
        ];

        $fpl_range = array(
            'min_fpl_' => $fpl - 1, 
            '' => $fpl, 
            'max_fpl_' => $fpl + 1
        );
        foreach ($fpl_range as $index => $f) {
            $key = array_search($f, array_column($max_mins, 'fpl'));
            if (is_int($key)) {
                $ret[$index . 'min'] = $max_mins[$key]['min'];
                $ret[$index . 'buffer'] = $max_mins[$key]['buffer'];
                $nextFPL = array_search($f + 1, array_column($max_mins, 'fpl'));
                $ret[$index . 'max'] = isset($max_mins[$nextFPL]) ? $max_mins[$nextFPL]['min'] - 1: null;
            }
            else {
                if ($f === $ret['min_level_fpl']) {
                  unset($ret['min_level_fpl']);
                }
                if ($f === $ret['max_level_fpl']) {
                  unset($ret['max_level_fpl']);
                }
                $ret[$index . 'min'] = null;
                $ret[$index . 'buffer'] = null;
                $ret[$index . 'max'] = null;
            }

        }

        return $ret;
    }

    private function add_facility_class_totals(array &$tci) :void {
        
        if (isset($tci['curTCI']) && $tci['tci'] !== $tci['curTCI']) {
            $tci['tower'] = $tci['facility_class'] === 'Tower' ? $tci['curTCI'] : $tci['tci'] - $tci['curTCI'];
            $tci['approach'] = $tci['facility_class'] === 'Approach' ? round($tci['curTCI'], 2) : round($tci['tci'] - $tci['curTCI'], 2);
        }
        else if (isset($tci['facility_class'])){
            $tci[strtolower($tci['facility_class'])] = $tci['tci'];
        }
        unset($tci['curTCI'], $tci['facility_class']);
    }

    private function get_national_averages(array $dates, array $filter): ?array
    {
        global $database;

        $sql = 'SELECT ftci.`date`, AVG(fte.`tci`) AS avg_tci, ft.name as facilityType, DATE_FORMAT(ftci.date, "%Y-%m") as yr_mth
                FROM facilities f 
                JOIN facility_profiles fp ON fp.facility_id = f.facility_id
                JOIN facility_types ft ON ft.facility_type_id = fp.facility_type_id
                JOIN facility_tcis ftci ON ftci.facility_id = f.facility_id
                JOIN facility_tci_entries fte ON ftci.facility_tci_id = fte.facility_tci_id
                JOIN facility_fpls ff ON ff.facility_id = f.facility_id
                WHERE f.tci = 1 ';
        $sql .= isset($filter['fpl']) && !empty($filter['facilityFPL']) ? ' AND ff.fpl = :fpl ' : '';
        $sql .= isset($filter['facilityType']) && !empty($filter['facilityType']) ? ' AND ft.facility_type_id = :facilityType ' : '';
        $sql .= 'AND ftci.date BETWEEN :startDate AND :endDate
                GROUP BY yr_mth';
        $stmt = $database->prepare($sql);
        $stmt->bindValue(':startDate', $dates['query_date'], PDO::PARAM_STR);
        $stmt->bindValue(':endDate', $dates['end_date'], PDO::PARAM_STR);
        if (strpos($sql, ':fpl') !== false ) {
            $stmt->bindValue(':fpl', $filter['fpl'], PDO::PARAM_INT);
        }
        if (strpos($sql, ':facilityType') !== false ) {
            $stmt->bindValue(':facilityType', $filter['facilityType'], PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function get_averages_by_facilityIds(array $dates, array $facilityIds): ?array
    {
        global $database;
        $facilityStr = ':' .implode(',:', $facilityIds);

        $sql = 'SELECT f.id AS facility, AVG(fte.`tci`) AS avg_tci, fte.tci, DATE_FORMAT(ftci.`date`, "%Y-%m") AS yr_mth
                FROM facilities f 
                JOIN facility_profiles fp USING (facility_id) 
                JOIN facility_types ft USING (facility_type_id)
                JOIN facility_tcis ftci USING (facility_id)
                JOIN facility_tci_entries fte USING (facility_tci_id)
                JOIN facility_fpls ff USING (facility_id)
                WHERE f.tci = 1
                AND ftci.date BETWEEN  :startDate AND :endDate
                AND f.facility_id IN (' . $facilityStr .')
                GROUP BY facility, yr_mth';
        $stmt = $database->prepare($sql);
        $stmt->bindValue(':startDate', $dates['query_date'], PDO::PARAM_STR);
        $stmt->bindValue(':endDate', $dates['end_date'], PDO::PARAM_STR);
        foreach ($facilityIds as $id) {
            $stmt->bindValue(sprintf(':%s', $id), $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function get_avg_tci_fpl(array $dates, int $fpl): ?array
    {
        global $database;

        $sql = 'SELECT f.id AS facility, AVG(fte.`tci`) AS avg_tci, fte.tci, DATE_FORMAT(ftci.`date`, "%Y-%m") AS yr_mth
                FROM facilities f 
                JOIN facility_profiles fp USING (facility_id) 
                JOIN facility_types ft USING (facility_type_id)
                JOIN facility_tcis ftci USING (facility_id)
                JOIN facility_tci_entries fte USING (facility_tci_id)
                JOIN facility_fpls ff USING (facility_id)
                WHERE f.tci = 1
                AND ftci.date BETWEEN :startDate AND :endDate
                AND ff.fpl = :fpl
                GROUP BY facility, yr_mth
                ORDER BY facility, yr_mth ASC';
        $stmt = $database->prepare($sql);
        $stmt->bindValue(':startDate', $dates['query_date'], PDO::PARAM_STR);
        $stmt->bindValue(':endDate', $dates['end_date'], PDO::PARAM_STR);
        $stmt->bindValue(':fpl', $fpl, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function get_avg_tci_facility_type(array $dates, int $facilityTypeId): ?array
    {
        global $database;

        $sql = 'SELECT f.id AS facility, ftci.`date`, AVG(fte.`tci`) AS avg_tci, ft.name as facilityType, DATE_FORMAT(ftci.date, "%Y-%m") as yr_mth
                FROM facilities f 
                JOIN facility_profiles fp ON fp.facility_id = f.facility_id
                JOIN facility_types ft ON ft.facility_type_id = fp.facility_type_id
                JOIN facility_tcis ftci ON ftci.facility_id = f.facility_id
                JOIN facility_tci_entries fte ON ftci.facility_tci_id = fte.facility_tci_id
                JOIN facility_fpls ff ON ff.facility_id = f.facility_id
                WHERE f.tci = 1
                AND ftci.date BETWEEN :startDate AND :endDate
                AND ft.facility_type_id = :facilityTypeId
                GROUP BY facility, yr_mth';
        $stmt = $database->prepare($sql);
        $stmt->bindValue(':startDate', $dates['query_date'], PDO::PARAM_STR);
        $stmt->bindValue(':endDate', $dates['end_date'], PDO::PARAM_STR);
        $stmt->bindValue(':facilityTypeId', $facilityTypeId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function find_national_rate_changes(array $record, array $records): ?array {
        $data = array(
            'metaData'  => array(),
            'data'      => array(),
        );
        list($year, $month) = explode('-', $record['date']);
        $findDate = $year+1 . '-' . $month;
        $key = array_search($findDate, array_column($records, 'yr_mth'));
        if ($key !== false) {
            $curMonth = $records[$key]; //Current month because it needs the rate from prev year
            $diff = $record['avg_tci'] - $curMonth['avg_tci'];
            $rate = bcdiv($diff, $record['avg_tci'], 4)* 100;
            $data['data'] = [ 
                'facility'  => 'Nat. Avg.',
                'diff'      => $diff,
                'rate'      => number_format($rate, 2),
                'date'      => $findDate,
            ];
            $data['metaData']['field'] = $findDate;
            $data['metaData']['cfield'] = array('name' => $findDate, 'type' => 'text');
            $data['metaData']['column'] =  array(
                'flex' => 1,
                'dataIndex' => $findDate,
                'text' => $findDate,
            );
        }
        else { 
            $data = null;
        }

        return $data;
    }

    private function find_facility_rate_changes(array $record, array $records): ?array {
        $data = array();
        list($year, $month) = explode('-', $record['yr_mth']);
        $findDate = $year+1 . '-' . $month;
        $facility = array_filter($records, function ($d) use ($record) {
            return $d['facility'] === $record['facility'];
        });
        $key = array_search($findDate, array_column($facility, 'yr_mth'));
        if ($key !== false) {
            $curMonth = $records[$key]; //Current month because it needs the rate from prev year
            $diff = $record['avg_tci'] - $curMonth['avg_tci'];
            $rate = bcdiv($diff, $record['avg_tci'], 4)* 100;
            $data = [ 
                'facility'  => $record['facility'],
                'diff'      => $diff,
                'rate'      => number_format($rate, 2),
                'date'      => $findDate,
                'dateField' => array(
                    'name' => $findDate,
                    'type' => 'float',
                ),
            ];
        }
        else { 
            $data = null;
        }

        return $data;
    }

    private function get_array_median(array $arr): float {
        sort($arr);
        $count = count($arr);
        $middleval = floor(($count - 1) / 2);
        if ($count % 2) {
            $median = $arr[$middleval];
        } 
        else { 
            $low = $arr[$middleval];
            $high = $arr[$middleval + 1];
            $median = (($low + $high) / 2);
        }
        
        return $median;
    }

    private function get_background_color_code(array $rates): array {
        $median = $this->get_array_median($rates);
        $max = max($rates);
        $min = min($rates);
        $negDiff = floor(($median - $min)/ 3);
        $posDiff = floor(($max - $median)/3);

        return array(
            'pink'      => ($median - $negDiff), 
            'lgtRed'    => ($median - ($negDiff * 2)), 
            'mint'      => ($median + $posDiff),
            'lgtGreen'  => ($median + ($posDiff * 2)),
            'median'    =>  $median, 
        );

    }

	private function create_store_proxy_change_chart(): array {
		return array(
				'type' 	=> 'ajax',
				'url' 	=> 'ajax/reports.php',
				'extraParams' => array(
					'method' 	=> 'get_tci_change_report_chart',
					'start_date' => date('Y-m-d'),
					'end_date'	=> date('Y-m-d', strtotime('-1 year')),
				),
				'reader' => array(
					'rootProperty' => 'data',
				)
		);

	}

/************************************
 * Date utility functions
 */

    /**
     * Function will give you the difference between two dates
     *
     * @param string $start_date
     * @param string $end_date
     * @return array|null
     */
    public function get_months_between_dates(string $start_date, string $end_date): ?array
    { //default is months
        $startDate = $start_date instanceof Datetime ? $start_date : new DateTime($start_date);
        $endDate = $end_date instanceof Datetime ? $end_date : new DateTime($end_date);
        $interval = $startDate->diff($endDate);
        $months = ($interval->y * 12) + $interval->m;
        $months = $startDate > $endDate ? -$months : $months;
        return [
            'start_date'    => $startDate,
            'duration'      => $months,
            'end_date'      => $endDate,
        ];
    }

    public function get_default_dates(string $interval): array
    {
        $start_date = new DateTime();
        $start_date->add(date_interval_create_from_date_string($interval));

        return array(
            'start_date'    => $start_date->format('Y-m-d'),
            'end_date'      => (new DateTime())->format('Y-m-d')
        );
    }

    /**
     * This function is a utility function as it will take a date or use today's date to offset a date
     * using text interval. ie: output a year from today get_date_with_interval('', '+1 year')
     * example 3 months before 2011-12-01 get_date_with_interval('2011-12-01', '-3 months')
     *
     * @param string $date
     * @param string $interval
     * @return string
     */
    public function get_date_with_interval(string $date = null, string $interval): string
    {
        $start_date = empty($date) ? new DateTime(): new DateTime($date);
        $start_date->add(date_interval_create_from_date_string($interval));

        return $start_date->format('Y-m-d');
    }

    private function breakpoint_percentage_between($tci, $breakpoint): array{
        switch (true) {
            case ($tci > $breakpoint['min']):
                $cal['fromBreakpoint'] = number_format(($tci / $breakpoint['max'] * 100) - 100, 2) -100;
                $cal['fromMin'] = number_format(($tci / $breakpoint['min'] * 100), 2) - 100;
                $cal['fromBuffer'] = 100 - number_format(($tci / $breakpoint['buffer'] * 100), 2);
                break;
            case ($tci <= $breakpoint['min'] && $tci > $breakpoint['buffer']):
                $cal['fromBreakpoint'] = number_format(($tci / $breakpoint['max'] * 100), 2) - 100;
                $cal['fromMin'] = number_format(($tci / $breakpoint['min'] * 100), 2) - 100;
                $cal['fromBuffer'] = 100 - number_format(($tci / $breakpoint['buffer'] * 100), 2);
                break;
            case ($tci >= $breakpoint['buffer']):
                $cal['fromBreakpoint'] = number_format(($tci / $breakpoint['max'] * 100), 2) - 100;
                $cal['fromMin'] = number_format(($tci / $breakpoint['min'] * 100), 2) - 100;
                $cal['fromBuffer'] = 100 - number_format(($tci / $breakpoint['buffer'] * 100), 2);
                break;
            default:
                $cal['fromBreakpoint'] = number_format(($tci / $breakpoint['max'] * 100), 2) - 100;
                $cal['fromMin'] = number_format(($tci / $breakpoint['min'] * 100), 2) - 100;
                $cal['fromBuffer'] = number_format(($tci / $breakpoint['buffer'] * 100), 2) - 100;
                break;
        }

        return $cal;
    }

    public function data_debugger($data, bool $die = true, bool $varDump = false, bool $pre = true ): void {
        echo $pre === true ? '<pre>' : '';
        $varDump === true ? var_dump($data) : print_r($data);
        echo $pre === true ? '</pre>' : '';
        $die === true ? die("\n ----- Data ${varDump} Debug -------------") : '';
    }
    
    
    /**** Traffic Count Reports ****/
    /**
     * Retrieve the data for average count report
     *
     * @param array $filter
     * @param array $sort
     * @return array
     */
    public function get_average_count_report(array $filter = NULL, array $sort = NULL): array {
        global $database, $facilities, $holidays;
        
        $facility_id = $filter !== NULL && isset($filter["facility_id"]) && is_numeric($filter["facility_id"]) && $filter["facility_id"] > 0 ? intval($filter["facility_id"]) : 0;
        $facility_class_id = $filter !== NULL && isset($filter["facility_class_id"]) && is_numeric($filter["facility_class_id"]) && $filter["facility_class_id"] > 0 ? intval($filter["facility_class_id"]) : 0;
        $profile = $facilities->get_current_profile($facility_id);
        $start_date = new DateTime($filter["start_date"]);
        $end_date = new DateTime($filter["end_date"]);
        $end_date = $end_date->modify("+1 day"); // Include end date 
        $interval = new DateInterval('P1D');
        $daterange = new DatePeriod($start_date, $interval ,$end_date);
        
        // Calcualte Facility hours between selected date range
        $hourCount = array();
        foreach($daterange as $date) {
            $key = $date->format("Y-m-d");
            $day = strtolower($date->format("D"));
            $hourCount[$key] = array();
            $isClosed = false;
            
            if(count($profile["hours_holiday"]) > 0) {
                $holiday_id = $holidays->get_holiday_id_from_date($key);
                foreach($profile["hours_holiday"] as $holiday) {
                    if($holiday_id == $holiday["holiday_id"]) {
                        if($holiday["closed"]) {
                            $isClosed = true;
                        } else {
                            for($i=1; $i<=3; $i++) {
                                if(is_numeric($holiday["open_hour".$i])) {
                                    $open_hour = intval($holiday["open_hour".$i]);
                                    $close_hour = intval($holiday["close_hour".$i]);
            
                                    for ($j = $open_hour; $j <= $close_hour; $j++) {
                                        $hourCount[$key][$j] = 1;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            if($isClosed) {
                continue;
            }
            
            if(count($profile["hours"]) > 0) {
                $defaultAdded = false;
                foreach($profile["hours"] as $hours) {
                    if($defaultAdded) {
                        break;
                    }
                    $hourStart = new DateTime($date->format("Y")."-".$hours["start_month"]."-".$hours["start_day"]);
                    $hourStop = new DateTime($date->format("Y")."-".$hours["stop_month"]."-".$hours["stop_day"]);
                    if($date >= $hourStart && $date <= $hourStop) {
                        // Add Hours
                        for($i=1; $i<=3; $i++) {
                            if(is_numeric($hours[$day."_open_hour".$i])) {
                                $open_hour = intval($hours[$day."_open_hour".$i]);
                                $close_hour = intval($hours[$day."_close_hour".$i]);
        
                                for ($j = $open_hour; $j <= $close_hour; $j++) {
                                    $hourCount[$key][$j] = 1;
                                }
                            }
                        }
                        $defaultAdded = true;
                    }
                }
            }
            if(count($profile["hours_adhoc"]) > 0){
                foreach($profile["hours_adhoc"] as $adhoc_hour) {
                    $hourStart = new DateTime($adhoc_hour["start"]);
                    $hourStop = new DateTime($adhoc_hour["stop"]);
                    if($date >= $hourStart && $date <= $hourStop) {
                        // Add Adhoc Hours
                        for($i=1; $i<=3; $i++) {
                            if(is_numeric($adhoc_hour["open_hour".$i])) {
                                $open_hour = intval($adhoc_hour["open_hour".$i]);
                                $close_hour = intval($adhoc_hour["close_hour".$i]);
        
                                for ($j = $open_hour; $j <= $close_hour; $j++) {
                                    $hourCount[$key][$j] = 1;
                                }
                            }
                        }
                    }
                }
            }
            if(count($profile["hours_recurring"]) > 0){
                foreach($profile["hours_recurring"] as $recurring_hour) {
                    $hourStart = new DateTime($recurring_hour["start"]);
                    $hourStop = new DateTime($recurring_hour["stop"]);
                    if($date >= $hourStart && $date <= $hourStop) {
                        // Add Adhoc Hours
                        for($i=1; $i<=3; $i++) {
                            if(is_numeric($recurring_hour["open_hour".$i])) {
                                $open_hour = intval($recurring_hour["open_hour".$i]);
                                $close_hour = intval($recurring_hour["close_hour".$i]);
        
                                for ($j = $open_hour; $j <= $close_hour; $j++) {
                                    $hourCount[$key][$j] = 1;
                                }
                            }
                        }
                    }
                }
            }
            $hourCount[$key] = array_sum($hourCount[$key]);
        }
        $totalHours = array_sum($hourCount);
        
        // Manual Count
        $sql = "SELECT tcm.`traffic_count_id`, tcm.`facility_class_id`, tcm.`hour`, SUM(tcme.`total`) as `count_total`, DAYNAME(tcm.`date`) as `dayname` FROM `traffic_counts_manual` tcm\n".
               "LEFT JOIN `traffic_counts_manual_entries` tcme ON tcme.`traffic_count_id` = tcm.`traffic_count_id`\n".
               "WHERE tcm.`facility_id`=? AND (tcm.`date` BETWEEN ? AND ?)\n".
               "AND tcme.`entry_id` IN(\n" .
               "    SELECT MAX(`entry_id`)\n" .
               "    FROM `traffic_counts_manual_entries`\n" .
               "    GROUP BY `traffic_count_id`\n" .
               ")\n";
        if($facility_class_id > 0) {
            $sql .= "AND tcm.`facility_class_id` = ?\n";
        }
        if($filter["selected_type"] == "hourly") {
            $sql .= "GROUP BY tcm.`hour`, tcm.`facility_class_id`";
        } elseif ($filter["selected_type"] == "dayofweek") {
            $sql .= "GROUP BY `dayname`, tcm.`facility_class_id`";
        }
        
        $statement = $database->prepare($sql);
        $statement->bindValue(1, $facility_id, PDO::PARAM_INT);
        $statement->bindValue(2, $filter["start_date"], PDO::PARAM_STR);
        $statement->bindValue(3, $filter["end_date"], PDO::PARAM_STR);
        if($facility_class_id > 0) {
            $statement->bindValue(4, $facility_class_id, PDO::PARAM_INT);
        }
        $statement->execute();
        $manualrecords = $statement->fetchAll();
        
        $hourly = array();
        $key = "hour";
        if($filter["selected_type"] == "hourly") {
            for($i = 0; $i < 24; $i++) {
                for($j = 1; $j <= 3; $j++) {
                    $hourly[$i]["class".$j] = 0;
                }
            }
        } elseif($filter["selected_type"] == "dayofweek") {
            $key = "dayname";
            $days = array("Sunday" => 0, "Monday" => 0, "Tuesday" => 0, "Wednesday" => 0, "Thursday" => 0, "Friday" => 0, "Saturday" => 0);
            for($j = 1; $j <= 3; $j++) {
                foreach($days as $k=>$v) {
                    $hourly[$k]["class".$j] = 0;
                }
            }
        }
        foreach($manualrecords as $manualrecord) {
            $hourly[$manualrecord[$key]]["class".$manualrecord["facility_class_id"]] += $manualrecord["count_total"];
        }
        
        // Automated Count
        $sql = "SELECT ftc.`facility_class_id`, ftc.`hour`, SUM(ftc.`value`) as `count_total`, DAYNAME(ftc.`date`) as `dayname` FROM abacus_flight.`facility_traffic_counts` ftc\n".
               "WHERE ftc.`facility_id`=? AND (ftc.`date` BETWEEN ? AND ?)\n";
        if($facility_class_id > 0) {
            $sql .= "AND ftc.`facility_class_id` = ?\n";
        }
        if($filter["selected_type"] == "hourly") {
            $sql .= "GROUP BY ftc.`hour`, ftc.`facility_class_id`";
        } elseif ($filter["selected_type"] == "dayofweek") {
            $sql .= "GROUP BY `dayname`, ftc.`facility_class_id`";
        }
        
        $statement = $database->prepare($sql);
        $statement->bindValue(1, $facility_id, PDO::PARAM_INT);
        $statement->bindValue(2, $filter["start_date"], PDO::PARAM_STR);
        $statement->bindValue(3, $filter["end_date"], PDO::PARAM_STR);
        if($facility_class_id > 0) {
            $statement->bindValue(4, $facility_class_id, PDO::PARAM_INT);
        }
        $statement->execute();
        $autorecords = $statement->fetchAll();
        foreach($autorecords as $autorecord) {
            $hourly[$autorecord[$key]]["class".$autorecord["facility_class_id"]] += $autorecord["count_total"];
        }
        
        $results = array();
        foreach($hourly as $k=>$v) {
            $totalOps = array_sum($v);
            extract($v);
            $results[] = array(
                "hour" => $key == 'hour' ? ($k+1) : 1,
                "total_ops" => $totalOps,
                "total_hrs" => $totalHours,
                "ops_per_hour" => round($totalOps/$totalHours, 8),
                "dayname" => $k,
                "ops_per_hour_class1" => round($class1/$totalHours, 8),
                "ops_per_hour_class2" => round($class2/$totalHours, 8),
                "ops_per_hour_class3" => round($class3/$totalHours, 8)
                );
        }
        
        return $results;
    }
    
    /**
     * Retrieve the data for manual count report
     *
     * @param array $filter
     * @param array $sort
     * @return array
     */
    public function get_manual_count_report(array $filter = NULL, array $sort = NULL): array {
        global $database, $facilities, $manual_traffic_counts;
        
        $facility_id = $filter !== NULL && isset($filter["facility_id"]) && is_numeric($filter["facility_id"]) && $filter["facility_id"] > 0 ? intval($filter["facility_id"]) : 0;
        $facility_class_id = $filter !== NULL && isset($filter["facility_class_id"]) && is_numeric($filter["facility_class_id"]) && $filter["facility_class_id"] > 0 ? intval($filter["facility_class_id"]) : 0;
        
        $sql = "SELECT tcm.`traffic_count_id`, tcme.`created_by_initials`, tcm.`traffic_count_type_id`,tcm.`date`, WEEKOFYEAR(tcm.`date`) AS `week`, YEAR(tcm.`date`) AS `year`, MONTHNAME(tcm.`date`) AS `month`, tcm.`hour`, tcm.`flight_rule_id`, tcm.`aircraft_category_id`,tcm.`aircraft_weight_id`,tcm.`srs_category_id`,tct.`name` AS `traffic_count_type`, fl.`name` AS `flight_rule`, ac.`name` AS `aircraft_category`,aw.`name` AS `aircraft_weight`,sc.`name` AS `srs_category`, tcm.`facility_id`, tcm.`facility_class_id`, tcme.`entry_id`, tcme.`created_by`, tcme.`total` AS `total`, f.`id` AS `facility`,tcme.`created_on`,CONCAT(a.`firstname`,' ',a.`lastname`) AS `created_by_name`\n" .
               ", tct.`name` as `traffic_count_type`, fl.`name` as `flight_rule`, ac.`name` as `aircraft_category`\n".
               "FROM `traffic_counts_manual` tcm\n" .
               "INNER JOIN `traffic_counts_manual_entries` tcme ON tcm.`traffic_count_id` = tcme.`traffic_count_id` \n" .
               "LEFT JOIN `traffic_count_types` tct ON tcm.`traffic_count_type_id` = tct.`traffic_count_type_id` \n" .
               "LEFT JOIN `facilities` f ON f.`facility_id`= tcm.`facility_id`\n" .
               "LEFT JOIN `flight_rules` fl ON tcm.`flight_rule_id` = fl.`flight_rule_id` \n" .
               "LEFT JOIN `aircraft_categories` ac ON tcm.`aircraft_category_id` = ac.`aircraft_category_id` \n" .
               "LEFT JOIN `aircraft_weights` aw ON tcm.`aircraft_weight_id` = aw.`aircraft_weight_id` \n" .
               "LEFT JOIN `srs_categories` sc ON tcm.`srs_category_id` = sc.`srs_category_id` \n" .
               "LEFT JOIN `accounts` a ON a.`account_id`= tcme.`created_by`\n".
               "WHERE tcm.`facility_id` = ? AND (tcm.`date` BETWEEN ? AND ?)\n";
        if($facility_class_id > 0) {
            $sql .= "AND tcm.`facility_class_id` = ?\n";
        }
        if($filter["selected_type"] == "hourly") {
            //$sql .= "GROUP BY tcm.`date`, tcm.`hour`, tcm.`traffic_count_type_id`, tcm.`flight_rule_id`, tcm.`aircraft_category_id`\n";
            //$sql .= "GROUP BY tcm.`date`\n";
        //} elseif($filter["selected_type"] == "weekly") {
        } else {
            $sql .= "AND tcme.`entry_id` IN(\n" .
                     "SELECT MAX(`entry_id`)\n" .
                     "FROM `traffic_counts_manual_entries`\n" .
                     "GROUP BY `traffic_count_id`)\n";
            //$sql .= "GROUP BY WEEKOFYEAR(tcm.`date`)";
        }
        $sql .= "ORDER BY tcm.`date`, tcm.`hour`, tcme.`entry_id` DESC";
        
        $statement = $database->prepare($sql);
        $statement->bindValue(1, $facility_id, PDO::PARAM_INT);
        $statement->bindValue(2, $filter["start_date"], PDO::PARAM_STR);
        $statement->bindValue(3, $filter["end_date"], PDO::PARAM_STR);
        if($facility_class_id > 0) {
            $statement->bindValue(4, $facility_class_id, PDO::PARAM_INT);
        }
        $statement->execute();
        $results = $statement->fetchAll();
        $manual_counts = array();
        
        //loop through the results and only store the latest manaul count per manual count id
        $columns = array();
        $groupstrArr = array("hourly" => ["date", "hour"],"weekly" => ["week","year"], "monthly" => ["month","year"], "yearly" => ["year"]);
        foreach ($results as $result) {
            $groupstr = "";
            foreach($groupstrArr[$filter["selected_type"]] as $k=>$v) {
                $groupstr .= $result[$v];
            }
            
            $traffic_count_id = intval($result["traffic_count_id"]);
            
            $columns[$result["flight_rule"]." ".$result["traffic_count_type"]][$result["aircraft_category"]] = 1;
            
            $fieldname = strtolower(str_replace(" ", "_", $result["flight_rule"]."_".$result["traffic_count_type"]."_".$result["aircraft_category"]));
            $result["counts"][$fieldname] = $result["total"];
            
            $dt = new DateTime();
            $result["week_start"] = $dt->setISODate($result["year"], $result["week"])->format("Y-m-d");
            $result["week_end"] = $dt->modify("+6 days")->format("Y-m-d");
            
            if(!array_key_exists($groupstr, $manual_counts)) {
                $manual_counts[$groupstr] = array();
            }
            
            if (!array_key_exists($traffic_count_id, $manual_counts[$groupstr])) {
                $this->format_entry($result);
                $result["id"] = $result["entry_id"];
                $result["expanded"] = false;
                $result["iconCls"] = "fas fa-plane";
                $created = DateTime::createFromFormat("U", $result["created_on"]);
                $result["entered_date"] = $created->format("Y-m-d");
                $result["entered_time"] = $created->format("H:i:s");
                
                $manual_counts[$groupstr][$traffic_count_id] = $result;
            }
            else if ($result["entry_id"] !== $manual_counts[$groupstr][$traffic_count_id]["entry_id"]) {
                if (!array_key_exists("data", $manual_counts[$groupstr][$traffic_count_id])) {
                    $manual_counts[$groupstr][$traffic_count_id]["data"] = array();
                }
                $this->format_entry($result);
                $result["id"] = $result["entry_id"];
                $result["iconCls"] = "fas fa-plane";
                $result["leaf"] = true;
                $created = DateTime::createFromFormat("U", $result["created_on"]);
                $result["entered_date"] = $created->format("Y-m-d");
                $result["entered_time"] = $created->format("H:i:s");

                array_push($manual_counts[$groupstr][$traffic_count_id]["data"], $result);
            }
        }
        
        $final_counts = array();
        foreach($manual_counts as $k=>$v) {
            if(count($v) > 0) {
                $total = 0;
                $counts = [];
                foreach($v as $k1=>$v1) {
                    foreach($v1["counts"] as $k2=>$v2) {
                        if(!array_key_exists($k2, $counts)) {
                            $counts[$k2] = 0;
                        }
                        $counts[$k2] += $v2;
                    }
                    $total += $v1["total"];
                }
                $v1["total"] = $total;
                $merged = array_merge($v1, $counts);
                array_push($final_counts, $merged);
            } else {
                array_push($final_counts, $v);
            }
        }
        //print_r($final_counts);exit;
        
        foreach($final_counts as &$manual_count) {
            
            //if no children for this parent were found, the 'data' array wouldn't have been created above
            $manual_count["leaf"] = !array_key_exists("data", $manual_count);
            if ($manual_count["leaf"]) {
                $manual_count["data"] = array();
            }
        }
        
        return array("data" => array_values($final_counts), "columns" => $columns, "metaData" => $columns);
    }
    
    private function format_entry(&$entry) {
        $entry["traffic_count_id"] = intval($entry["traffic_count_id"]);
        $entry["facility_id"] = intval($entry["facility_id"]);
        $entry["facility_class_id"] = intval($entry["facility_class_id"]);
        $entry["entry_id"] = intval($entry["entry_id"]);
        $entry["traffic_count_type_id"] = intval($entry["traffic_count_type_id"]);
        $entry["flight_rule_id"] = intval($entry["flight_rule_id"]);
        $entry["aircraft_category_id"] = intval($entry["aircraft_category_id"]);
        $entry["aircraft_weight_id"] = intval($entry["aircraft_weight_id"]);
        $entry["srs_category_id"] = intval($entry["srs_category_id"]);
        $entry["total"] =  intval($entry["total"]);
        $entry["hour"] =  intval($entry["hour"]);
        $entry["created_by"] =  intval($entry["created_by"]);
        $entry["created_by_initials"] = $entry["created_by_initials"] ?? "";
        $entry["created_by_name"] = $entry["created_by_name"] ?? "";
        $entry["aicraft_weight"] = $entry["aircraft_weight"] ?? "";
        $entry["srs_category"] = $entry["srs_category"] ?? "";
    }

    public function fakeData(array $filter = [], int $count = 255): array {
        global $database;
        
        $sql = 'SELECT f.id AS facility, f.facility_id, ft.date
                FROM facility_tcis ft 
                JOIN facilities f USING (facility_id)
                WHERE 1 = 1
                AND YEAR(ft.DATE) = 2021 AND MONTH(ft.DATE) =  03;';
        $stmt = $database->prepared($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        if ($results !== false) {
            foreach ($results as &$row) {
                $row['facility_class_id'] = rand(1, 3);
                $row['facility_tci_id'] = rand(100000, 101607);
                $row['tci'] = rand(18, 500)/10;
            }
        }

        return $results;
    }

}
