<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;
use CRM_Cdntaxreceipts_Utils_MK as MK;
/**
 * This class provides the common functionality for issuing Aggregate Tax Receipts for
 * a group of Contribution ids.
 */
class CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts extends CRM_Contribute_Form_Task {

  const MAX_RECEIPT_COUNT = 2000; // FIXME : PUT IN setting

  private $_contributions_status;
  private $_issue_type;
  private $_receipts;
  private $_years;

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {

    //check for permission to edit contributions
    if ( ! CRM_Core_Permission::check('issue cdn tax receipts') ) {
      CRM_Core_Error::fatal(E::ts('You do not have permission to access this page', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

    _cdntaxreceipts_check_requirements();

    parent::preProcess();

    $this->_contributions_status = array();
    $this->_issue_type = array('original' , 'duplicate');
    $this->_receipts = array();
    $this->_years = array();

    $receipts = array('totals' =>
      array(
        'total_contrib' => 0,
        'loading_errors' => 0,
        'total_contacts' => 0,
        'original' => 0,
        'duplicate' => 0,
      ),
    );

    $this->_contributions_status = cdntaxreceipts_contributions_get_status($this->_contributionIds);

    // Get the number of years selected
    foreach ($this->_contributions_status as $contrib_status) {
      $this->_years[$contrib_status['receive_year']] = $contrib_status['receive_year'];
    }

    foreach ( $this->_years as $year ) {
      foreach ($this->_issue_type as $issue_type) {
        $receipts[$issue_type][$year] = array(
          'total_contrib' => 0,
          'total_amount' => 0,
          'email' => array('contribution_count' => 0, 'receipt_count' => 0,),
          'print' => array('contribution_count' => 0, 'receipt_count' => 0,),
          'data' => array('contribution_count' => 0, 'receipt_count' => 0,),
          'total_contacts' => 0,
          'total_eligible_amount' => 0,
          'not_eligible' => 0,
          'not_eligible_amount' => 0,
          'contact_ids' => array(),
        );
      }
    }

    // Count and categorize contributions
    foreach ($this->_contributionIds as $id) {
      $status = isset($this->_contributions_status[$id]) ? $this->_contributions_status[$id] : NULL;
      if (is_array($status)) {
        $year = $status['receive_year'];
        $issue_type = empty($status['receipt_id']) ? 'original' : 'duplicate';
        $receipts[$issue_type][$year]['total_contrib']++;
        // Note: non-deductible amount has already had hook called in cdntaxreceipts_contributions_get_status
        $receipts[$issue_type][$year]['total_amount'] += ($status['total_amount']);
        $receipts[$issue_type][$year]['not_eligible_amount'] += $status['non_deductible_amount'];
        if ($status['eligible']) {
          list( $method, $email ) = cdntaxreceipts_sendMethodForContact($status['contact_id']);
          $receipts[$issue_type][$year][$method]['contribution_count']++;
          if (!isset($receipts[$issue_type][$year]['contact_ids'][$status['contact_id']])) {
            $receipts[$issue_type][$year]['contact_ids'][$status['contact_id']] = array(
              'issue_method' => $method,
              'contributions' => array(),
            );
            $receipts[$issue_type][$year][$method]['receipt_count']++;
          }
          // Here we store all the contribution details for each contact_id
          $receipts[$issue_type][$year]['contact_ids'][$status['contact_id']]['contributions'][$id] = $status;
        }
        else {
          $receipts[$issue_type][$year]['not_eligible']++;
          // $receipts[$issue_type][$year]['not_eligible_amount'] += $status['total_amount'];
        }
        // Global totals
        $receipts['totals']['total_contrib']++;
        $receipts['totals'][$issue_type]++;
        if ($status['contact_id']) {
          $receipts['totals']['total_contacts']++;
        }
      }
      else {
        $receipts['totals']['loading_errors']++;
      }
    }

    foreach ($this->_issue_type as $issue_type) {
      foreach ($this->_years as $year) {
        $receipts[$issue_type][$year]['total_contacts'] = count($receipts[$issue_type][$year]['contact_ids']);
      }
    }

    $this->_receipts = $receipts;

  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {

    CRM_Utils_System::setTitle(E::ts('Issue Aggregate Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')));

    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/civicrm_cdntaxreceipts.css');

    $this->assign('receiptList', $this->_receipts);
    $this->assign('receiptYears', $this->_years);

    $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
    $this->assign('deliveryMethod', $delivery_method);

    // add radio buttons
    // TODO: It might make sense to issue for multiple years here so switch to checkboxes
    foreach ( $this->_years as $year ) {
      $this->addElement('radio', 'receipt_year', NULL, $year, 'issue_' . $year);
    }
    $this->addRule('receipt_year', E::ts('Selection required', array('domain' => 'org.civicrm.cdntaxreceipts')), 'required');

    if ($delivery_method != CDNTAX_DELIVERY_DATA_ONLY) {
      $this->add('checkbox', 'is_preview', E::ts('Run in preview mode?', array('domain' => 'org.civicrm.cdntaxreceipts')) );
    }

    $buttons = array(
      array(
        'type' => 'cancel',
        'name' => E::ts('Back', array('domain' => 'org.civicrm.cdntaxreceipts')),
      ),
      array(
        'type' => 'next',
        'name' => 'Issue Tax Receipts',
        'isDefault' => TRUE,
        'submitOnce' => TRUE,
      ),
    );
    $this->addButtons($buttons);

  }

  function setDefaultValues() {
    // TODO: Handle case where year -1 was not an option
    return array('receipt_year' => 'issue_' . (date("Y") - 1),
                  'is_preview' => true,
                );
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return None
   */

  function postProcess() {

    $params = $this->controller->exportValues($this->_name);
    $year = $params['receipt_year'];
    if ( $year ) {
      $year = substr($year, strlen('issue_')); // e.g. issue_2012
    }
    
    $iReceipts_Org = -1;
    $iReceipts_Ind = -1;
    $iReceipts_Original = -1;
    $receipts_List_Organization = array();
    $receipts_List_Individual = array();
    $receipts_List_Original = array();

    $org_id = 0;
    $ind_id = 0;
    // Retrieves the list of Organization and Individual
    // Civi::log()->info('AVANT FOREACH CONTRIBUTION_STATUS '); // : '.print_r($this->_receipts['original'],1));
    foreach ($this->_receipts['original'][$year]['contact_ids'] as $contact_id => $contribution_status) {
      // $contact_type = MK::get_contact_type[$contact_id];

      $contacts = \Civi\Api4\Contact::get(FALSE)
        ->addWhere('id', '=', $contact_id)
        ->execute();
      foreach ($contacts as $contact) {
        $contact_type = $contact['contact_type'];
        break;
      }

      switch ($contact_type){
        case 'Organization' :
          if ($org_id ==0 ) {$org_id = $contact_id;}
          $iReceipts_Org ++;
          array_push($receipts_List_Organization, $contribution_status);
          break;
        case 'Individual' :
          if ($ind_id ==0 ) {$ind_id = $contact_id;}
          $iReceipts_Ind ++;
          array_push($receipts_List_Individual, $contribution_status);
          break;
        default :
          $iReceipts_Original ++;
          array_push($receipts_List_Original, $contribution_status);
          break;
      }
    }

    $params = $this->controller->exportValues($this->_name);

    // Civi::log()->info('IssueAggregateTaxReceipts.php > receipts_List_Organization isset() : '.isset($receipts_List_Organization).' empty() : '.empty($receipts_List_Organization).' receipts_List_Organization : '.print_r( $receipts_List_Organization,1));
    // Civi::log()->info('IssueAggregateTaxReceipts.php > receipts_List_Individual isset() : '.isset($receipts_List_Individual).' empty() : '.empty($receipts_List_Individual).' receipts_List_Individual : '.print_r( $receipts_List_Individual,1));
    // Civi::log()->info('IssueAggregateTaxReceipts.php > receipts_List_Original isset() : '.isset($receipts_List_Original).' empty() : '.empty($receipts_List_Original).' receipts_List_Original : '.print_r( $receipts_List_Original,1));

    // $mergePDF = new FPDF_Merge();
    if (isset($receipts_List_Organization)){
      $this->specific_type_of_contact($receipts_List_Organization, $org_id);
      // $receiptsForPrintingPDF = $this->specific_type_of_contact($receipts_List_Organization, $org_id);
      // $mergePDF.add($receiptsForPrintingPDF);
    }
    if (isset($receipts_List_Individual)){
      $this->specific_type_of_contact($receipts_List_Individual, $ind_id);
      // $receiptsForPrintingPDF = $this->specific_type_of_contact($receipts_List_Individual, $ind_id);
      // $mergePDF.add($receiptsForPrintingPDF);
    }
    if (isset($receipts_List_Original)){
      $this->original($receipts_List_Original);
      // $receiptsForPrintingPDF = $this->original($receipts_List_Original);
      // $mergePDF.add($receiptsForPrintingPDF);
    }

    // // 4. send the collected PDF for download
    // // NB: This exits if a file is sent.
    // Civi::log()->info('IssueAggregateTaxReceipts.php > specific_type_of_contact BEFORE cdntaxreceipts_sendCollectedPDF');
    // cdntaxreceipts_sendCollectedPDF($mergePDF, 'Receipts-To-Print-' . CRM_Cdntaxreceipts_Utils_Time::time() . '.pdf');  // EXITS.
    // // cdntaxreceipts_sendCollectedPDF($receiptsForPrintingPDF, 'Receipts-To-Print-' . CRM_Cdntaxreceipts_Utils_Time::time() . '.pdf');  // EXITS.

  }

  /************************************
   * Organization OR Individual receipt
   * Make receipts for a specific type of contact
   * 
   * @param $contributions_status : array des contribution
   ************************************/
  function specific_type_of_contact($contributions_status, $contact_id) {
    Civi::log()->info('IssueAggregateTaxReceipts.php > specific_type_of_contact contributionS_status : '.print_r( $contributions_status,1));

    // lets get around the time limit issue if possible
    if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }

    $params = $this->controller->exportValues($this->_name);
    $year = $params['receipt_year'];
    if ( $year ) {
      $year = substr($year, strlen('issue_')); // e.g. issue_2012
    }

    $previewMode = FALSE;
    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    // start a PDF to collect receipts that cannot be emailed
    $receiptsForPrintingPDF = cdntaxreceipts_openCollectedPDF($contact_id);

    $emailCount = 0;
    $printCount = 0;
    $dataCount = 0;
    $failCount = 0;
    
    
    //foreach ($this->_receipts['original'][$year]['contact_ids'] as $contact_id => $contribution_status) {
    foreach ($contributions_status as $contribution_status) {
      
      if ( $emailCount + $printCount + $failCount >= self::MAX_RECEIPT_COUNT ) {
        // limit email, print receipts as the pdf generation and email-to-archive consume
        // server resources. don't limit data-type receipts.
        $status = E::ts('Maximum of %1 tax receipt(s) were sent. Please repeat to continue processing.',
          array(1=>self::MAX_RECEIPT_COUNT, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'info');
        break;
      }

      $contributions = $contribution_status['contributions'];
      $method = $contribution_status['issue_method'];

      if ( empty($issuedOn) && count($contributions) > 0 ) {
        Civi::log()->info('IssueAggregateTaxReceipts.php > specific_type_of_contact BEFORE CALL cdntaxreceipts_issueAggregateTaxReceipt');
        
        $ret = cdntaxreceipts_issueAggregateTaxReceipt($contact_id, $year, $contributions, $method,
          $receiptsForPrintingPDF, $previewMode);
        
        Civi::log()->info('IssueAggregateTaxReceipts.php > specific_type_of_contact AFTER CALL cdntaxreceipts_issueAggregateTaxReceipt');
        
        if ( $ret == 0 ) {
          $failCount++;
        }
        elseif ( $method == 'email' ) {
          $emailCount++;
        }
        elseif ( $method == 'print' ) {
          $printCount++;
        }
        elseif ( $method == 'data' ) {
          $dataCount++;
        }
      }
    }

    // 3. Set session status
    if ( $previewMode ) {
      Civi::log()->info('IssueAggregateTaxReceipts.php > specific_type_of_contact BEFORE 3.SET Session status');
      $status = E::ts('%1 tax receipt(s) have been previewed.  No receipts have been issued.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus($status, '', 'success');
      Civi::log()->info('IssueAggregateTaxReceipts.php > specific_type_of_contact AFTER 3.SET Session status');
    }
    else {
      if ($emailCount > 0) {
        $status = E::ts('%1 tax receipt(s) were sent by email.', array(1=>$emailCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
      if ($printCount > 0) {
        $status = E::ts('%1 tax receipt(s) need to be printed.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
      if ($dataCount > 0) {
        $status = E::ts('Data for %1 tax receipt(s) is available in the Tax Receipts Issued report.', array(1=>$dataCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
    }

    if ( $failCount > 0 ) {
      $status = E::ts('%1 tax receipt(s) failed to process.', array(1=>$failCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus($status, '', 'error');
    }

    // return $receiptsForPrintingPDF;
    // 4. send the collected PDF for download
    // NB: This exits if a file is sent.
    cdntaxreceipts_sendCollectedPDF($receiptsForPrintingPDF, 'Receipts-To-Print-' . CRM_Cdntaxreceipts_Utils_Time::time() . '.pdf');  // EXITS.
  }



  /************************************
   *  ***   ORIGINAL
   ************************************/
  function original($contributions_status) {
    // Civi::log()->info('IssueAggregateTaxReceipts.php > original  contributionS_status : '.print_r( $contributions_status,1));

    // lets get around the time limit issue if possible
    if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }

    $params = $this->controller->exportValues($this->_name);
    $year = $params['receipt_year'];
    if ( $year ) {
      $year = substr($year, strlen('issue_')); // e.g. issue_2012
    }

    $previewMode = FALSE;
    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    // start a PDF to collect receipts that cannot be emailed
    $receiptsForPrintingPDF = cdntaxreceipts_openCollectedPDF();

    $emailCount = 0;
    $printCount = 0;
    $dataCount = 0;
    $failCount = 0;
    
    
    //foreach ($this->_receipts['original'][$year]['contact_ids'] as $contact_id => $contribution_status) {
    foreach ($this->_receipts['original'][$year]['contact_ids'] as $contact_id => $contribution_status) {
     // Civi::log()->info('IssueAggregateTaxReceipts.php > postProcess  contribution_status : '.print_r( $contribution_status,1));
      // AB : Take the right PDF depending on the type of contact  
      // $receiptsForPrintingPDF = cdntaxreceipts_openCollectedPDF($contact_id);

      
      if ( $emailCount + $printCount + $failCount >= self::MAX_RECEIPT_COUNT ) {
        // limit email, print receipts as the pdf generation and email-to-archive consume
        // server resources. don't limit data-type receipts.
        $status = E::ts('Maximum of %1 tax receipt(s) were sent. Please repeat to continue processing.',
          array(1=>self::MAX_RECEIPT_COUNT, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'info');
        break;
      }

      $contributions = $contribution_status['contributions'];
      $method = $contribution_status['issue_method'];

      if ( empty($issuedOn) && count($contributions) > 0 ) {
        $ret = cdntaxreceipts_issueAggregateTaxReceipt($contact_id, $year, $contributions, $method,
          $receiptsForPrintingPDF, $previewMode);

        if ( $ret == 0 ) {
          $failCount++;
        }
        elseif ( $method == 'email' ) {
          $emailCount++;
        }
        elseif ( $method == 'print' ) {
          $printCount++;
        }
        elseif ( $method == 'data' ) {
          $dataCount++;
        }
      }
    }

    // 3. Set session status
    if ( $previewMode ) {
      $status = E::ts('%1 tax receipt(s) have been previewed.  No receipts have been issued.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus($status, '', 'success');
    }
    else {
      if ($emailCount > 0) {
        $status = E::ts('%1 tax receipt(s) were sent by email.', array(1=>$emailCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
      if ($printCount > 0) {
        $status = E::ts('%1 tax receipt(s) need to be printed.', array(1=>$printCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
      if ($dataCount > 0) {
        $status = E::ts('Data for %1 tax receipt(s) is available in the Tax Receipts Issued report.', array(1=>$dataCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'success');
      }
    }

    if ( $failCount > 0 ) {
      $status = E::ts('%1 tax receipt(s) failed to process.', array(1=>$failCount, 'domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus($status, '', 'error');
    }

    // return $receiptsForPrintingPDF;
    // 4. send the collected PDF for download
    // NB: This exits if a file is sent.
    cdntaxreceipts_sendCollectedPDF($receiptsForPrintingPDF, 'Receipts-To-Print-' . CRM_Cdntaxreceipts_Utils_Time::time() . '.pdf');  // EXITS.
  }


}

