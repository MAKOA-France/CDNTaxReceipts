<?php

/**
 * This class provides the common functionality for issuing Annual Tax Receipts for
 * one or a group of contact ids.
 */
class CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts extends CRM_Contact_Form_Task {

  const MAX_RECEIPT_COUNT = 1000;

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

    $thisYear = date("Y");
    $this->_years = array($thisYear, $thisYear - 1, $thisYear - 2);

    $receipts = array();
    foreach ( $this->_years as $year ) {
      $receipts[$year] = array('email' => 0, 'print' => 0, 'data' => 0, 'total' => 0, 'contrib' => 0);
    }

    // count and categorize contributions
    foreach ( $this->_contactIds as $id ) {
      foreach ( $this->_years as $year ) {
        list( $issuedOn, $receiptId ) = cdntaxreceipts_annual_issued_on($id, $year);

        $eligible = count(cdntaxreceipts_contributions_not_receipted($id, $year));
        if ( $eligible > 0 ) {
          list( $method, $email ) = cdntaxreceipts_sendMethodForContact($id);
          $receipts[$year][$method]++;
          $receipts[$year]['total']++;
          $receipts[$year]['contrib'] += $eligible;
        }
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

    CRM_Utils_System::setTitle(ts('Issue Annual Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')));

    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/civicrm_cdntaxreceipts.css');

    // assign the counts
    $receipts = $this->_receipts;
    $receiptTotal = 0;
    foreach ( $this->_years as $year ) {
      $receiptTotal += $receipts[$year]['total'];
    }

    $this->assign('receiptCount', $receipts);
    $this->assign('receiptTotal', $receiptTotal);
    $this->assign('receiptYears', $this->_years);

    $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
    $this->assign('deliveryMethod', $delivery_method);

    // add radio buttons
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
    return array('receipt_year' => 'issue_' . (date("Y") - 1),);
  }


  function processOneReceipt($contactId, $year, $previewMode) {
    sleep(2);
  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return None
   */

  function postProcess() {

    // lets get around the time limit issue if possible
    /*if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }*/

    $params = $this->controller->exportValues($this->_name);
    $year = $params['receipt_year'];
    if ( $year ) {
      $year = substr($year, strlen('issue_')); // e.g. issue_2012
    }

    $previewMode = FALSE;
    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    $queueName = 'cdntaxreceipt_' . time();

    $queue = Civi::queue($queueName, [
      'type' => 'Sql',
      'runner' => 'task',
      'error' => 'abort',
    ]);
    
    foreach ($this->_contactIds as $contactId ) {
      $queue->createItem(new CRM_Queue_Task(
        ['CRM_Cdntaxreceipts_Utils', 'processOneReceipt'], // callback
        [$contactId, $year, $previewMode], // arguments
        "Contact $contactId" // title
      ));
    }

    \Civi\Api4\UserJob::create()->setValues([
      'job_type' => 'contact_import',
      'status_id:name' => 'in_progress',
      'queue_id.name' => $queue->getName(),
      'metadata' => [
        'preview' => $previewMode,
        'year' => $year,
        'print' => [],
        'count' => [
          'email' => 0,
          'print' => 0,
          'data' => 0,
          'fail' => 0,
          'total' => count($this->_contactIds),
        ],
      ],
    ])->execute();
    
    // get queue for transfer id in url of page end
    $queueReceipt = \Civi\Api4\Queue::get(TRUE)
      ->addSelect('*', 'custom.*')
      ->addWhere('name', '=', $queue->getName())
      ->execute()
      ->first();

    $parameterPageEndQueueReceipt = $queueReceipt['id'];

    $runner = new CRM_Queue_Runner([
      'title' => ts('Generating Receipts'),
      'queue' => $queue,
      // Deprecated; only works on AJAX runner // 'onEnd' => ['CRM_Demoqueue_Page_DemoQueue', 'onEnd'],
      'onEndUrl' => CRM_Utils_System::url('civicrm/cdntaxreceipts/queue-result?queue=' . $parameterPageEndQueueReceipt),
    ]);
    // redirect to a specific queue page
    $runner->runAllInteractive();

  }
}

