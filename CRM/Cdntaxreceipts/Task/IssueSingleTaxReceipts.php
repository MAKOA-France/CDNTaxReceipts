<?php

require_once('CRM/Contribute/Form/Task.php');

use CRM_Cdntaxreceipts_ExtensionUtil as E;

/**
 * This class provides the common functionality for issuing CDN Tax Receipts for
 * one or a group of contact ids.
 */
class CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts extends CRM_Contribute_Form_Task {

  const MAX_RECEIPT_COUNT = 2000; // FIXME : PUT IN setting

  private $_receipts;

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

    $receipts = array( 'original'  => array('email' => 0, 'print' => 0, 'data' => 0),
                       'duplicate' => array('email' => 0, 'print' => 0, 'data' => 0), );

    // count and categorize contributions
    foreach ( $this->_contributionIds as $id ) {
      if ( cdntaxreceipts_eligibleForReceipt($id) ) {
        list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($id);
        $key = empty($issued_on) ? 'original' : 'duplicate';
        list( $method, $email ) = cdntaxreceipts_sendMethodForContribution($id);
        $receipts[$key][$method]++;
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

    CRM_Utils_System::setTitle(E::ts('Issue Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')));

    // assign the counts
    $receipts = $this->_receipts;
    $originalTotal = $receipts['original']['print'] + $receipts['original']['email'] + $receipts['original']['data'];
    $duplicateTotal = $receipts['duplicate']['print'] + $receipts['duplicate']['email'] + $receipts['duplicate']['data'];
    $receiptTotal = $originalTotal + $duplicateTotal;
    $this->assign('receiptCount', $receipts);
    $this->assign('originalTotal', $originalTotal);
    $this->assign('duplicateTotal', $duplicateTotal);
    $this->assign('receiptTotal', $receiptTotal);

    $delivery_method = Civi::settings()->get('delivery_method') ?? CDNTAX_DELIVERY_PRINT_ONLY;
    $this->assign('deliveryMethod', $delivery_method);

    // add radio buttons
    $this->addElement('radio', 'receipt_option', NULL, E::ts('Issue tax receipts for the %1 unreceipted contributions only.', array(1=>$originalTotal, 'domain' => 'org.civicrm.cdntaxreceipts')), 'original_only');
    $this->addElement('radio', 'receipt_option', NULL, E::ts('Issue tax receipts for all %1 contributions. Previously-receipted contributions will be marked \'duplicate\'.', array(1=>$receiptTotal, 'domain' => 'org.civicrm.cdntaxreceipts')), 'include_duplicates');
    $this->addRule('receipt_option', E::ts('Selection required', array('domain' => 'org.civicrm.cdntaxreceipts')), 'required');

    if ($delivery_method != CDNTAX_DELIVERY_DATA_ONLY) {
      $this->add('checkbox', 'is_preview', E::ts('Run in preview mode?', array('domain' => 'org.civicrm.cdntaxreceipts')));
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
    return array('receipt_option' => 'original_only',
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
    Civi::log()->info('IssueSingleTaxReceipts.php > postProcess _contributionIds : '.print_r($this->_contributionIds,1));
    
    $i_Org = -1;
    $i_Ind = -1;
    $i_Original = -1;
    $contributionIds_Organization = array();
    $contributionIds_Individual = array();
    $contributionIds_Original = array();

    $org_id = 0;
    $ind_id = 0;

    foreach ($this->_contributionIds as $item => $contributionId) {
      $contributions = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('contact_id.contact_type', 'contact_id')
        ->addWhere('id', '=', $contributionId)
        ->execute();
      foreach ($contributions as $contribution) {
        $contact_type = $contribution['contact_id.contact_type'];
        $contact_id = $contribution['contact_id'];
      }
      
      switch ($contact_type){
        case 'Organization' :
          if ($org_id ==0 ) {$org_id = $contact_id;}
          $i_Org ++;
          array_push($contributionIds_Organization, $contributionId);
          break;
        case 'Individual' :
          if ($ind_id ==0 ) {$ind_id = $contact_id;}
          $i_Ind ++;
          array_push($contributionIds_Individual, $contributionId);
          break;
        default :
          $i_Original ++;
          array_push($contributionIds_Original, $contributionId);
          break;
      }

    }

   
    Civi::log()->info('IssueSingleTaxReceipts.php > contributionIds_Organization isset() : '.isset($contributionIds_Organization).' contributionIds_Organization : '.print_r( $contributionIds_Organization,1));
    Civi::log()->info('IssueSingleTaxReceipts.php > contributionIds_Individual isset() : '.isset($contributionIds_Individual).' contributionIds_Individual : '.print_r( $contributionIds_Individual,1));
    Civi::log()->info('IssueSingleTaxReceipts.php > contributionIds_Original isset() : '.isset($contributionIds_Original).' contributionIds_Original : '.print_r( $contributionIds_Original,1));


    // $mergePDF = new FPDF_Merge();
    if (isset($contributionIds_Organization)){
      $this->original($contributionIds_Organization, $org_id);
      // $receiptsForPrintingPDF = $this->specific_type_of_contact($contributionIds_Organization, $org_id);
      // $mergePDF.add($receiptsForPrintingPDF);
    }
    if (isset($contributionIds_Individual)){
      $this->original($contributionIds_Individual, $ind_id);
      // $receiptsForPrintingPDF = $this->specific_type_of_contact($contributionIds_Individual, $ind_id);
      // $mergePDF.add($receiptsForPrintingPDF);
    }
    if (isset($contributionIds_Original)){
      $this->original($contributionIds_Original);
      // $receiptsForPrintingPDF = $this->original($contributionIds_Original);
      // $mergePDF.add($receiptsForPrintingPDF);
    }


  }

  /************************************
   *  ***   ORIGINAL
   ************************************/
  function original($contributionIds, $contact_id = 0) {
    Civi::log()->info('original contact_id : '.print_r($contact_id ,1));
    // lets get around the time limit issue if possible
    if ( ! ini_get( 'safe_mode' ) ) {
      set_time_limit( 0 );
    }

    $params = $this->controller->exportValues($this->_name);

    $originalOnly = FALSE;
    if ($params['receipt_option'] == 'original_only') {
      $originalOnly = TRUE;
    }

    $previewMode = FALSE;
    if (isset($params['is_preview']) && $params['is_preview'] == 1 ) {
      $previewMode = TRUE;
    }

    /**
     * Drupal module include
     */
    //module_load_include('.inc','civicrm_cdntaxreceipts','civicrm_cdntaxreceipts');
    //module_load_include('.module','civicrm_cdntaxreceipts','civicrm_cdntaxreceipts');

    // start a PDF to collect receipts that cannot be emailed
    if ($contact_id > 0){
      $receiptsForPrinting = cdntaxreceipts_openCollectedPDF($contact_id);
    }else{
      $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
    }
    

    $emailCount = 0;
    $printCount = 0;
    $dataCount = 0;
    $failCount = 0;

    foreach ($contributionIds as $item => $contributionId) {

      if ( $emailCount + $printCount + $failCount >= self::MAX_RECEIPT_COUNT ) {
        // limit email, print receipts as the pdf generation and email-to-archive consume
        // server resources. don't limit data-type receipts.
        $status = E::ts('Maximum of %1 tax receipt(s) were sent. Please repeat to continue processing.', array(1=>self::MAX_RECEIPT_COUNT, 'domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus($status, '', 'info');
        break;
      }

      // 1. Load Contribution information
      $contribution = new CRM_Contribute_DAO_Contribution();
      $contribution->id = $contributionId;
      if ( ! $contribution->find( TRUE ) ) {
        CRM_Core_Error::fatal( "CDNTaxReceipts: Could not find corresponding contribution id." );
      }

      // 2. If Contribution is eligible for receipting, issue the tax receipt.  Otherwise ignore.
      if ( cdntaxreceipts_eligibleForReceipt($contribution->id) ) {

        list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contribution->id);
        if ( empty($issued_on) || ! $originalOnly ) {

          list( $ret, $method ) = cdntaxreceipts_issueTaxReceipt( $contribution, $receiptsForPrinting, $previewMode );

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

    // 4. send the collected PDF for download
    // NB: This exits if a file is sent.
    cdntaxreceipts_sendCollectedPDF($receiptsForPrinting, 'Receipts-To-Print-' . (int) $_SERVER['REQUEST_TIME'] . '.pdf');  // EXITS.
  }
}

