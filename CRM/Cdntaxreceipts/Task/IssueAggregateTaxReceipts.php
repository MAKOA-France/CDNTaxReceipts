<?php

/**
 * This class provides the common functionality for issuing Aggregate Tax Receipts for
 * a group of Contribution ids.
 */
class CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts extends CRM_Contribute_Form_Task {

  const MAX_RECEIPT_COUNT = 1000;

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
      CRM_Core_Error::fatal(ts('You do not have permission to access this page', array('domain' => 'org.civicrm.cdntaxreceipts')));
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
    if (empty($this->_contributions_status)) {
      // e.g. if all the contributions selected were in-kind
      CRM_Core_Error::statusBounce(ts('No eligible contributions selected. E.g. In-kind must be receipted individually.', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

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
          //_cdntaxreceipts_check_lineitems($id);
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

    CRM_Utils_System::setTitle(ts('Issue Aggregate Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')));

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
    $this->addRule('receipt_year', ts('Selection required', array('domain' => 'org.civicrm.cdntaxreceipts')), 'required');

    if ($delivery_method != CDNTAX_DELIVERY_DATA_ONLY) {
      $this->add('checkbox', 'is_preview', ts('Run in preview mode?', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

    $buttons = array(
      array(
        'type' => 'cancel',
        'name' => ts('Back', array('domain' => 'org.civicrm.cdntaxreceipts')),
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
    return array('receipt_year' => 'issue_' . (date("Y") - 1),);
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

    $previewMode = FALSE;
    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    $tasks = [];
    foreach ($this->_receipts['original'][$year]['contact_ids'] as $contactId => $contribution_status) {
      
      $contributions = $contribution_status['contributions'];
      $method = $contribution_status['issue_method'];

      if (empty($issuedOn) && count($contributions) > 0) {
        $tasks[] = new CRM_Queue_Task(
          ['CRM_Cdntaxreceipts_Utils_Queue', 'processOneAggregateReceipt'], // callback
          [$contactId, $year, $previewMode, $contribution_status], // arguments
          "Contact $contactId" // title
        );
      }
    }

    $queue = CRM_Cdntaxreceipts_Utils_Queue::createQueue($year, $previewMode, $tasks, 'contact');
    
    // get queue for transfer id in url of page end
    $queueId = CRM_Cdntaxreceipts_Utils_Queue::getQueueId($queue);
    
    $runner = new CRM_Queue_Runner([
      'title' => ts('Generating Receipts'),
      'queue' => $queue,
      // Deprecated; only works on AJAX runner // 'onEnd' => ['CRM_Demoqueue_Page_DemoQueue', 'onEnd'],
      'onEndUrl' => CRM_Utils_System::url('civicrm/cdntaxreceipts/queue-done?queue=' . $queueId),
    ]);
    // redirect to a specific queue page
    $runner->runAllInteractive();

  }
}

