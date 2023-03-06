<?php

require_once 'cdntaxreceipts.civix.php';
require_once 'cdntaxreceipts.functions.inc';
require_once 'cdntaxreceipts.db.inc';

use CRM_Cdntaxreceipts_ExtensionUtil as E;

//require_once 'include/tcpdf_fonts.php';

define('CDNTAXRECEIPTS_MODE_BACKOFFICE', 1);
define('CDNTAXRECEIPTS_MODE_PREVIEW', 2);
define('CDNTAXRECEIPTS_MODE_WORKFLOW', 3);

function cdntaxreceipts_civicrm_buildForm( $formName, &$form ) {
  if (is_a( $form, 'CRM_Contribute_Form_ContributionView')) {
    // add "Issue Tax Receipt" button to the "View Contribution" page
    // if the Tax Receipt has NOT yet been issued -> display a white maple leaf icon
    // if the Tax Receipt has already been issued -> display a red maple leaf icon

    CRM_Core_Resources::singleton()->addStyleFile('org.civicrm.cdntaxreceipts', 'css/civicrm_cdntaxreceipts.css');

    $contributionId = $form->get('id');
    $buttons = array(
      array(
        'type' => 'cancel',
        'name' => E::ts('Done'),
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
        'isDefault' => TRUE,
      )
    );
    $subName = 'view_tax_receipt';
    if ( isset($contributionId) && cdntaxreceipts_eligibleForReceipt($contributionId) ) {
      list($issued_on, $receipt_id) = cdntaxreceipts_issued_on($contributionId);
      $is_original_receipt = empty($issued_on);

      if ($is_original_receipt) {
        $subName = 'issue_tax_receipt';
      }

      $buttons[] = array(
        'type'      => 'submit',
        'subName'   => $subName,
        'name'      => E::ts('Tax Receipt', array('domain' => 'org.civicrm.cdntaxreceipts')),
        'isDefault' => FALSE,
        'icon'      => 'fa-check-square',
      );
      $form->addButtons($buttons);
    }
  }
}

/**
 * Implementation of hook_civicrm_postProcess().
 *
 * Called when a form comes back for processing. Basically, we want to process
 * the button we added in cdntaxreceipts_civicrm_buildForm().
 */

function cdntaxreceipts_civicrm_postProcess( $formName, &$form ) {

  // first check whether I really need to process this form
  if ( ! is_a( $form, 'CRM_Contribute_Form_ContributionView' ) ) {
    return;
  }

  // Is it one of our tax receipt buttons?
  $buttonName = $form->controller->getButtonName();
  if ($buttonName !== '_qf_ContributionView_submit_issue_tax_receipt' && $buttonName !== '_qf_ContributionView_submit_view_tax_receipt') {
    return;
  }

  // the tax receipt button has been pressed.  redirect to the tax receipt 'view' screen, preserving context.
  $contributionId = $form->get( 'id' );
  $contactId = $form->get( 'cid' );

  $session = CRM_Core_Session::singleton();
  $session->pushUserContext(CRM_Utils_System::url('civicrm/contact/view/contribution',
    "reset=1&id=$contributionId&cid=$contactId&action=view&context=contribution&selectedChild=contribute"
  ));

  $urlParams = array('reset=1', 'id='.$contributionId, 'cid='.$contactId);
  CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/cdntaxreceipts/view', implode('&',$urlParams)));
}

/**
 * Implementation of hook_civicrm_searchTasks().
 *
 * For users with permission to issue tax receipts, give them the ability to do it
 * as a batch of search results.
 */

function cdntaxreceipts_civicrm_searchTasks($objectType, &$tasks ) {
  if ( $objectType == 'contribution' && CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {
    $single_in_list = FALSE;
    $aggregate_in_list = FALSE;
    foreach ($tasks as $key => $task) {
      if($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts') {
        $single_in_list = TRUE;
      }
    }
    foreach ($tasks as $key => $task) {
      if($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts') {
        $aggregate_in_list = TRUE;
      }
    }
    if (!$single_in_list) {
      $tasks[] = array (
        'title' => E::ts('Issue Tax Receipts (Separate Receipt for Each Contribution)', array('domain' => 'org.civicrm.cdntaxreceipts')),
        'class' => 'CRM_Cdntaxreceipts_Task_IssueSingleTaxReceipts',
        'result' => TRUE);
    }
    if (!$aggregate_in_list) {
      $tasks[] = array (
        'title' => E::ts('Issue Tax Receipts (Combined Receipt with Total Contributed)'),
        'class' => 'CRM_Cdntaxreceipts_Task_IssueAggregateTaxReceipts',
        'result' => TRUE);
    }
  }
  elseif ( $objectType == 'contact' && CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {
    $annual_in_list = FALSE;
    foreach ($tasks as $key => $task) {
      if($task['class'] == 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts') {
        $annual_in_list = TRUE;
      }
    }
    if (!$annual_in_list) {
      $tasks[] = array (
        'title' => E::ts('Issue Annual Tax Receipts'),
        'class' => 'CRM_Cdntaxreceipts_Task_IssueAnnualTaxReceipts',
        'result' => TRUE);
    }
  }
}

/**
 * Implementation of hook_civicrm_permission().
 */
function cdntaxreceipts_civicrm_permission( &$permissions ) {
  $prefix = E::ts('CiviCRM CDN Tax Receipts') . ': ';
  $permissions += array(
    'issue cdn tax receipts' => $prefix . E::ts('Issue Tax Receipts', array('domain' => 'org.civicrm.cdntaxreceipts')),
  );
}


/**
 * Implementation of hook_civicrm_config
 */
function cdntaxreceipts_civicrm_config(&$config) {
  _cdntaxreceipts_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu
 *
 * @param $files array(string)
 */
function cdntaxreceipts_civicrm_xmlMenu(&$files) {
  _cdntaxreceipts_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install
 */
function cdntaxreceipts_civicrm_install() {
  // copy tables civicrm_cdntaxreceipts_log and civicrm_cdntaxreceipts_log_contributions IF they already exist
  // Issue: #1
  return _cdntaxreceipts_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 */
function cdntaxreceipts_civicrm_postInstall() {
  _cdntaxreceipts_civix_civicrm_postInstall();
}

/**
 * Implementation of hook_civicrm_uninstall
 */
function cdntaxreceipts_civicrm_uninstall() {
  return _cdntaxreceipts_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable
 */
function cdntaxreceipts_civicrm_enable() {
  CRM_Core_Session::setStatus(E::ts('Configure the Tax Receipts extension at Administer >> CiviContribute >> CDN Tax Receipts.', array('domain' => 'org.civicrm.cdntaxreceipts')));
  return _cdntaxreceipts_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_disable
 */
function cdntaxreceipts_civicrm_disable() {
  return _cdntaxreceipts_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed  based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 */
function cdntaxreceipts_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _cdntaxreceipts_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 */
function cdntaxreceipts_civicrm_managed(&$entities) {
  return _cdntaxreceipts_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_caseTypes
 */
function cdntaxreceipts_civicrm_caseTypes(&$caseTypes) {
  _cdntaxreceipts_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules
 */
function cdntaxreceipts_civicrm_angularModules(&$angularModules) {
  _cdntaxreceipts_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsFolders
 */
function cdntaxreceipts_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _cdntaxreceipts_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
 */
function cdntaxreceipts_civicrm_entityTypes(&$entityTypes) {
  _cdntaxreceipts_civix_civicrm_entityTypes($entityTypes);
}

/**
 * Implements hook_civicrm_themes().
 */
function cdntaxreceipts_civicrm_themes(&$themes) {
  _cdntaxreceipts_civix_civicrm_themes($themes);
}

/**
 * Implementation of hook_civicrm_navigationMenu
 *
 * Add entries to the navigation menu, automatically removed on uninstall
 */

function cdntaxreceipts_civicrm_navigationMenu(&$params) {

  // Check that our item doesn't already exist
  $cdntax_search = array('url' => 'civicrm/cdntaxreceipts/settings?reset=1');
  $cdntax_item = array();
  CRM_Core_BAO_Navigation::retrieve($cdntax_search, $cdntax_item);

  if ( ! empty($cdntax_item) ) {
    return;
  }

  // Get the maximum key of $params using method mentioned in discussion
  // https://issues.civicrm.org/jira/browse/CRM-13803
  $navId = CRM_Core_DAO::singleValueQuery("SELECT max(id) FROM civicrm_navigation");
  if (is_integer($navId)) {
    $navId++;
  }
  // Find the Memberships menu
  foreach($params as $key => $value) {
    if ('Administer' == $value['attributes']['name']) {
      $parent_key = $key;
      foreach($value['child'] as $child_key => $child_value) {
        if ('CiviContribute' == $child_value['attributes']['name']) {
          $params[$parent_key]['child'][$child_key]['child'][$navId] = array (
            'attributes' => array (
              'label' => E::ts('CDN Tax Receipts',array('domain' => 'org.civicrm.cdntaxreceipts')),
              'name' => 'CDN Tax Receipts',
              'url' => 'civicrm/cdntaxreceipts/settings?reset=1',
              'permission' => 'access CiviContribute,administer CiviCRM',
              'operator' => 'AND',
              'separator' => 2,
              'parentID' => $child_key,
              'navID' => $navId,
              'active' => 1
            )
          );
        }
      }
    }
  }
}

function cdntaxreceipts_civicrm_validate( $formName, &$fields, &$files, &$form ) {
  if ($formName == 'CRM_Cdntaxreceipts_Form_Settings') {
    $errors = array();
    $allowed = array('gif', 'png', 'jpg', 'pdf');
    foreach ($files as $key => $value) {
      if (CRM_Utils_Array::value('name', $value)) {
        $ext = pathinfo($value['name'], PATHINFO_EXTENSION);
        if (!in_array($ext, $allowed)) {
          $errors[$key] = E::ts('Please upload a valid file. Allowed extensions are (.gif, .png, .jpg, .pdf)');
        }
      }
    }
    return $errors;
  }
}

function cdntaxreceipts_civicrm_alterMailParams(&$params, $context) {
  /*
    When CiviCRM core sends receipt email using CRM_Core_BAO_MessageTemplate, this hook was invoked twice:
    - once in CRM_Core_BAO_MessageTemplate::sendTemplate(), context "messageTemplate"
    - once in CRM_Utils_Mail::send(), which is called by CRM_Core_BAO_MessageTemplate::sendTemplate(), context "singleEmail"

    Hence, cdntaxreceipts_issueTaxReceipt() is called twice, sending 2 receipts to archive email.

    To avoid this, only execute this hook when context is "messageTemplate"
  */
  if( $context != 'messageTemplate'){
    return;
  }

  $msg_template_types = array('contribution_online_receipt', 'contribution_offline_receipt');

  // Both of these are replaced by the same value of 'workflow' in 5.47
  $groupName = isset($params['groupName']) ? $params['groupName'] : (isset($params['workflow']) ? $params['workflow'] : '');
  $valueName = isset($params['valueName']) ? $params['valueName'] : (isset($params['workflow']) ? $params['workflow'] : '');
  if (($groupName == 'msg_tpl_workflow_contribution' || $groupName == 'contribution_online_receipt' || $groupName == 'contribution_offline_receipt')
      && in_array($valueName, $msg_template_types)) {

    // get the related contribution id for this message
    if (isset($params['tplParams']['contributionID'])) {
      $contribution_id = $params['tplParams']['contributionID'];
    }
    else if( isset($params['contributionId'])) {
      $contribution_id = $params['contributionId'];
    }
    else {
      return;
    }

    // is the extension configured to send receipts attached to automated workflows?
    if (!Civi::settings()->get('attach_to_workflows')) {
      return;
    }

    // is this particular donation receiptable?
    if (!cdntaxreceipts_eligibleForReceipt($contribution_id)) {
      return;
    }

    $contribution = new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contribution_id;
    $contribution->find(TRUE);

    $nullVar = NULL;
    list($ret, $method, $pdf_file) = cdntaxreceipts_issueTaxReceipt(
      $contribution,
      $nullVar,
      CDNTAXRECEIPTS_MODE_WORKFLOW
    );

    if ($ret) {
      $last_in_path = strrpos($pdf_file, '/');
      $clean_name = substr($pdf_file, $last_in_path);
      $attachment = array(
        'fullPath' => $pdf_file,
        'mime_type' => 'application/pdf',
        'cleanName' => $clean_name,
      );
      $params['attachments'] = array($attachment);
    }

  }

}

/***********************************/
/**       MAKOA TEST              **/
/***********************************/
/**
 * Implements Hook_cdntaxreceipts_writeReceipt().
 * /var/aegir/platforms/civicrm-d9/vendor/civicrm/org.civicrm.cdntaxreceipts/cdntaxreceipts.functions.inc
 */
function cdntaxreceipts_cdntaxreceipts_writeReceipt(&$f, $pdf_variables, $receipt) {
  
  // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt');
  //$fontPathRegular = '/var/aegir/platforms/civicrm-d9/sites/fne-dev.test.makoa.net/files/civicrm/custom/Type Dynamic - Predige Rounded.otf';
  //$fontPathRegular = '/var/aegir/platforms/civicrm-d9/sites/fne-dev.test.makoa.net/files/civicrm/custom/Outlands Truetype.ttf';
  //         $regularFont = $receipt->addTTFfont($fontPathRegular, '', '', 32);
  // $font = new TCPDF_FONTS();
  // $regularFont = $font->addTTFfont($fontPathRegular);
  
  // $f->AddFont('ok','','ok.php');
  // $f->AddFont('outlandstruetype','','outlandstruetype.php');
  // $f->AddFont('PredigeRounded','','typedynamicpredigerounded.php');
  // $f->AddFont('Roboto','','Roboto.php');
  $f->AddFont('OpenSans','','opensans.php');
  
  // //$f->AddFont('PredigeRounded','','predigerounded.php');
  
  // // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt > pdf_variables : '.print_r($pdf_variables,1));
  // //  Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt > receipt : '.print_r($receipt,1));
  //   // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt > f : '.print_r($f,1));
  // // //Letter details 
  // //_cdntaxreceipts_updateLetter($f, $pdf_variables);
  
  //Prints onlys one copy after the letter
  $pdf_variables['margin_top'] = $pdf_variables['mymargin_top'] + 0;
  $contact_id = $receipt['contact_id'];
  $contacts = \Civi\Api4\Contact::get(FALSE)
    ->addWhere('id', '=', $contact_id)
    ->execute();
  foreach ($contacts as $contact) {
    $contact_type = $contact['contact_type'];
    break;
  }

  // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt > contact_type : '.print_r($contact_type,1));
  switch ($contact_type){
    case 'Individual' :
      _writeReceipt($f, $pdf_variables, $receipt);
      break;
    case 'Organization' :
      _writeReceipt_Org($f, $pdf_variables, $receipt);
      break;
  }

  

  //Otherwise the receipts are printed twice
  return [TRUE];
}

function _updateLetter(&$f, $pdf_variables) {
}

function _writeReceipt(&$pdf, $pdf_variables, $receipt) {
  $espace_incecable = ' ';
  // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt()');
  // Extract variables
  $contact_id = $receipt['contact_id'];
  
  // @todo Why do we do this?
  $mode = $pdf_variables["mode"];
  $mymargin_left = $pdf_variables["mymargin_left"];
  $mymargin_top = 2; //$pdf_variables["mymargin_top"];
  $is_duplicate = $pdf_variables["is_duplicate"];
  $pdf_img_files_path = $pdf_variables["pdf_img_files_path"];
  $line_1 = $pdf_variables["line_1"];
  $source_funds = $pdf_variables["source_funds"];
  $amount = $pdf_variables["amount"];
  $display_date = $pdf_variables["display_date"];
  $issued_on = $pdf_variables["issued_on"];
  $location_issued = $pdf_variables["location_issued"];
  $receipt_number = $pdf_variables["receipt_number"];
  $displayname = $pdf_variables["displayname"];
  $address_line_1 = $pdf_variables["address_line_1"];
  $address_line_1b = $pdf_variables["address_line_1b"];
  $address_line_2 = $pdf_variables["address_line_2"];
  $address_line_3 = $pdf_variables["address_line_3"];
  $inkind_values = $pdf_variables["inkind_values"];
  $display_year = $pdf_variables["display_year"];
  $issue_type = $pdf_variables["issue_type"];
  $receipt_contributions = $pdf_variables['receipt_contributions'];
  $receipt_status = $pdf_variables['receipt_status'];
 
  $address = _getaddress($contact_id);
  $street_address = $address['street_address'];
  $supplemental_address_1 = $address['supplemental_address_1'];
  $supplemental_address_2 = $address['supplemental_address_2'];
  $supplemental_address_3 = $address['supplemental_address_3'];
  $postal_code = $address['postal_code'];
  $city = $address['city'];
  $country = $address['country'];
  //Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > pdf_variables : '.print_r($pdf_variables,1));
  
  //Format date 2023-12-01 en 01/12/2023
  $issued_on = CRM_Cdntaxreceipts_Utils_MK::date_format_fr($issued_on); // substr($issued_on,8,2).'/'.substr($issued_on,5,2).'/'.substr($issued_on,0,4);

  // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > pdf_variables : '.print_r($pdf_variables,1));

  // Middle center section
  if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
     $pdf->Image($pdf_img_files_path . 'brouillon_mode.jpg', $mymargin_left + 100, $mymargin_top, '', 45);     
  }
  else if ($receipt_status == 'cancelled') {
    $pdf->Image($pdf_img_files_path . 'cancelled_trans.png', $mymargin_left + 65, $mymargin_top, '', 45);
  }
  else if ($is_duplicate) {
    $pdf->Image($pdf_img_files_path . 'duplicate_trans.png', $mymargin_left + 65, $mymargin_top, '', 45);
  }
  
  $fontFNE = 'OpenSans';
   
  // *******************************
  //      N°ORDRE DU RECU
  // *******************************
  $x_detailscolumn = 164;
  $y_detailscolumnstart = 6.4;
  // $x_detailscolumn = 165;
  // $y_detailscolumnstart = 6;
  $pdf->SetFont($fontFNE, '', 8.5); 
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0);
  $pdf->Cell(24 ,6, E::ts("%1", array(1 => $receipt_number, 'domain' => 'org.civicrm.cdntaxreceipts')),0,0,'L',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
  //$pdf->Write(10, E::ts("%1", array(1 => $receipt_number, 'domain' => 'org.civicrm.cdntaxreceipts')));
  //$pdf->SetFont($fontFNE, '', 10.5);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 4.6);
  $pdf->Cell(24 ,6, E::ts("%1", array(1 => $contact_id)),0,0,'L',FALSE,'');

  // *******************************
  //      ENCART ADRESSE
  // *******************************
  $x_detailscolumn = 100;
  $y_detailscolumnstart = 42;

  // $addresse_string = '';
  // if (!empty($supplemental_address_1)) $addresse_string .=  $pdf->ln(10).$supplemental_address_1;
  // if (!empty($supplemental_address_2)) $addresse_string .=  $pdf->ln(10).$supplemental_address_2;
  // if (!empty($street_address)) $addresse_string .=  $pdf->ln(10).$street_address;
  // $addresse_string .=  $pdf->ln(10).$postal_code.' '.$city;  
  // if (!empty($country)) $addresse_string .= $pdf->ln(10).$country;

  // //mb_strtoupper($displayname);
  // $bloc_address_1 = mb_strtoupper($displayname).$addresse_string;
  // $bloc_address_2 = $displayname.$addresse_string;

  // $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart );
  // $pdf->MultiCell(94, 30, $bloc_address_1, 1, 'C',FALSE); // http://www.fpdf.org/en/doc/multicell.htm

  $niv = 1;
  $address_line_1 = '';
  $address_line_2 = '';
  $address_line_3 = '';
  $address_line_4 = '';
  $address_line_5 = '';
  if (!empty($supplemental_address_1)) {
    $address_line_1 = $supplemental_address_1;
    $niv ++;
  }
  if (!empty($supplemental_address_2)) {
    switch ($niv){
      case 1 : $address_line_1 = $supplemental_address_2; $niv++; break;
      case 2 : $address_line_2 = $supplemental_address_2; $niv++; break;
    }
  }
  if(!empty($street_address)){
    switch ($niv){
      case 1 : $address_line_1 = $street_address; $niv++; break;
      case 2 : $address_line_2 = $street_address; $niv++; break;
      case 3 : $address_line_3 = $street_address; $niv++; break;
    }
  }
  switch ($niv){
    case 1 : $address_line_1 = $postal_code.' '.$city; $niv++; break;
    case 2 : $address_line_2 = $postal_code.' '.$city; $niv++; break;
    case 3 : $address_line_3 = $postal_code.' '.$city;  $niv++; break;
    case 4 : $address_line_4 = $postal_code.' '.$city;  $niv++; break;
  }
  if (!empty($country) ){
    if (mb_strtoupper($country) == 'FRANCE'){

    }else{
      switch ($niv){
        case 1 : $address_line_1 = $country; $niv++; break;
        case 2 : $address_line_2 = $country; $niv++; break;
        case 3 : $address_line_3 = $country; $niv++; break;
        case 4 : $address_line_4 = $country; $niv++; break;
        case 5 : $address_line_5 = $country; $niv++; break;
      }
    }
  }
  

  $yplus = -1;
  $yinterligne = 6;
  $fontzise = 12;
  if (strlen($displayname) > 35) {   $fontzise = 6; $yinterligne -= 1;}
  $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  $yplus++;
  if (strlen($displayname) > 73 ){
    $fontzise -= 1.5; // $yinterligne -= 1;
    $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  }
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
  $pdf->Write(10, mb_strtoupper($displayname), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  if (strlen($displayname) > 73 ){
    $fontzise += 1.5; // $yinterligne += 1;
    $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  }

  if (!empty($address_line_1)){
    $yplus++;
    if (strlen($address_line_1) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
    $pdf->Write(10, mb_strtoupper($address_line_1), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_1) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }

  }
  if (!empty($address_line_2)){
    $yplus++;
    if (strlen($address_line_2) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_2), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_2) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
  
  }
  if (!empty($address_line_3)){
    $yplus++;
   // $pdf = _display_line_address($pdf, $fontFNE, $font_size, $address_line_3, $mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
    if (strlen($address_line_3) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_3), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_3) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
  }
  if (!empty($address_line_4)){
    $yplus++;
    if (strlen($address_line_4) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_4), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_4) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }

  }
  if (!empty($address_line_5)){
    $yplus++;
    if (strlen($address_line_5) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_5), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_5) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }

  }

  // ***************************************
  //    ENCART NOM ET ADRESSE DU DONATEUR
  // ***************************************
  $x_detailscolumn = 1.5;
  $y_detailscolumnstart = 136;
  $yplus = -1;
  $yinterligne = 4.5;
  $fontzise = 10;
  $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  $yplus++;
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
  //$pdf->Cell(24 ,9, $displayname,1,0,'L',FALSE,'');
  if (strlen($displayname) > 35) { $pdf->SetFont($fontFNE, '', 7, '', true); $yinterligne -= 1;}
  $pdf->Write(10, $displayname, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  if (!empty($address_line_1)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
    $pdf->Write(10, $address_line_1, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_2)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_2, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_3)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_3, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_4)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_4, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_5)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_5, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }


  // *******************************
  //      ENCART MONTANT
  // *******************************
  $x_detailscolumn = 96;
  $y_detailscolumnstart = 96.5;
  // $amount = '494 494,94';
  // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > pdf_variables : '.print_r($pdf_variables,1));
  $convert = new CRM_Cdntaxreceipts_Utils_ConvertNum($amount, 'EUR');
  $amount = $convert->getFormated(" ", "," );
  $amount = str_replace(' EUR', $espace_incecable.'€', $amount );
  $pdf->SetFont($fontFNE, '', 18);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 23); //25
  // $pdf->Cell(94 ,9,  '***'.mb_strtoupper($amount).'***',0,0,'C',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
  $pdf->Write(10, '***'.mb_strtoupper($amount).'***', '', 0, 'C', TRUE, 0, FALSE, FALSE, 0);
  
  // *** Display amount_letter ***
  // $convert = new CRM_Cdntaxreceipts_Utils_ConvertNum(str_replace(',','.',$amount), 'EUR');
  $amount_letter = $convert->convert("fr-FR");
  $amount_letter = mb_strtoupper(substr($amount_letter,0,1)).substr($amount_letter,1);
  $amount_letter_array = CRM_Cdntaxreceipts_Utils_MK::cutStringByWord($amount_letter,60);
  $font_size = 10;
  $pdf->SetFont($fontFNE, '', $font_size);
  $iarr = 0;
  $displayAmountLetter = '';
  foreach($amount_letter_array as $value){
    $iarr +=1;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 26 + ($iarr*4));
    $pdf->Write(5, $value, '', 0, 'C', FALSE, 0, FALSE, FALSE, 0);
    //$displayAmountLetter .= $pdf->ln(10).$value;   
  }
  // if (!empty($displayAmountLetter)) $displayAmountLetter = substr($displayAmountLetter,2);
  // $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 32.5 + ($iarr*4));
  // $pdf->MultiCell(94, 10, $displayAmountLetter, 1, 'C',FALSE); // http://www.fpdf.org/en/doc/multicell.htm
 

  // Afficher DATE après FAit à PAris, le
  $x_detailscolumn = 125;// 118;
  $y_detailscolumnstart = 139.2; // 138.8;
  $pdf->SetFont($fontFNE, '', 12);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($issued_on), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);


  // *******************************
  //      ECART LIST OF DON
  // *******************************
  $x_detailscolumn = 0;
  $y_detailscolumnstart = 187;

  $x_valeur = 26;
  $x_nature = 54;
  $x_mode = 78;
  $x_affectation = $x_mode + 53;
  $x_date = $x_affectation + 38;
  
  $y_ligne = 5;
  $num_ligne = -1;

  $don_id = '';  // $$receipt_contributions[0]['contribution_id'];
  $don_mtt = '';  // $$receipt_contributions[0]['contribution_amount'];
  $don_nature = 'Autre';  //Numéraire ...
  $don_mode = '';  // CHEQUE , VIREMENT etc $$receipt_contributions[0]['contribution_amount']; payment_instrument
  $don_affectation = '';  //HERISSON / Opération Hérisson
  $don_date = $receipt_contributions[0]['receive_date'];
  $don_date = CRM_Cdntaxreceipts_Utils_MK::date_format_fr($don_date);

  $pdf->SetFont($fontFNE, '', 8);
  foreach($receipt_contributions as $rc){
    $contribution_id = $rc['contribution_id'];
    // Civi::log()->info('contribution_id : '.print_r($contribution_id,1)); //cp1252

    $contributions = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contact_id', 'receive_date', 'total_amount', 'source', 'financial_type_id:label', 'contribution_status_id:label', 'payment_instrument_id:label', 'payment_instrument_id:description')
      ->addWhere('id', '=', $contribution_id)
      ->execute();
    foreach ($contributions as $contribution) {
     // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > contribution : '.print_r($contribution,1));
      $num_ligne ++;
      $don_id = $contribution['id'];
      $don_mtt = $contribution['total_amount'];
      $convert = new CRM_Cdntaxreceipts_Utils_ConvertNum(str_replace(',','.',$don_mtt), 'EUR');
      $don_mtt = $convert->getFormated(" ", "," );
      $don_mtt = str_replace(' EUR', $espace_incecable.'€', $don_mtt );

      $don_mode = $contribution['payment_instrument_id:label']; 
      $don_nature = $contribution['payment_instrument_id:description']; // TODO  $contribution['total_amount']; // 'Numéraire', 'Don en nature', Autre
      $don_mode_code = $contribution['payment_instrument_id:label']; //
      
      $don_affectation = '';
      $don_affectation_code = $contribution['source'];
      $optionValues = \Civi\Api4\OptionValue::get(FALSE)
        ->addWhere('option_group_id:name', '=', 'fne_type_affectation')
        ->addWhere('value', '=', $don_affectation_code)
        ->execute();
      foreach ($optionValues as $optionValue) {
        $don_affectation = $optionValue['label'];
      }
      if (empty($don_affectation)){
        $optionValues = \Civi\Api4\OptionValue::get(FALSE)
          ->addWhere('option_group_id:name', '=', 'fne_type_affectation')
          ->addWhere('value', '=', 'default')
          ->execute();
        foreach ($optionValues as $optionValue) {
          $don_affectation = $optionValue['label'];
        }
      }
      $don_date = CRM_Cdntaxreceipts_Utils_MK::date_format_fr( $contribution['receive_date']); 


      $h = 4.5; $b= 0;

      $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($num_ligne*$y_ligne));
      $pdf->Cell(23, $h, $don_id, $b, 0,'C',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
      // $pdf->Write(9, $don_id, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    
      $pdf->SetXY( $mymargin_left + $x_detailscolumn + $x_valeur, $mymargin_top + $y_detailscolumnstart + ($num_ligne*$y_ligne));
      $pdf->Cell(27, $h, $don_mtt, $b, 0,'R',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
    
      $pdf->SetXY($mymargin_left + $x_detailscolumn + $x_nature, $mymargin_top + $y_detailscolumnstart + ($num_ligne*$y_ligne));
      $pdf->Cell(23, $h, $don_nature, $b, 0,'C',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
      // $pdf->Write(9, $don_nature, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

      $pdf->SetXY($mymargin_left + $x_detailscolumn + $x_mode, $mymargin_top + $y_detailscolumnstart + ($num_ligne*$y_ligne));
      $pdf->Cell(52, $h, $don_mode, $b, 0,'C',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
      // $pdf->Write(9, $don_mode, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

      $pdf->SetXY($mymargin_left + $x_detailscolumn + $x_affectation, $mymargin_top + $y_detailscolumnstart + ($num_ligne*$y_ligne));
      $pdf->Cell(37, $h, $don_affectation, $b, 0,'C',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
      //  $pdf->Write(9, $don_affectation, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

      $pdf->SetXY($mymargin_left + $x_detailscolumn + $x_date, $mymargin_top + $y_detailscolumnstart + ($num_ligne*$y_ligne));
      $pdf->Cell(19, $h, $don_date, $b, 0,'C',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
      // $pdf->Write(9, $don_date, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    } 
  }

}


function _writeReceipt_Org(&$pdf, $pdf_variables, $receipt) {
  $espace_incecable = ' ';
 // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt > _writeReceipt_Org()'); // : '.print_r($pdf_variables,1));
  // Extract variables
  $contact_id = $receipt['contact_id'];
  
  // @todo Why do we do this?
  $mode = $pdf_variables["mode"];
  $mymargin_left = $pdf_variables["mymargin_left"];
  $mymargin_top = 2; //$pdf_variables["mymargin_top"];
  $is_duplicate = $pdf_variables["is_duplicate"];
  $pdf_img_files_path = $pdf_variables["pdf_img_files_path"];
  $line_1 = $pdf_variables["line_1"];
  $source_funds = $pdf_variables["source_funds"];
  $amount = $pdf_variables["amount"];
  $display_date = $pdf_variables["display_date"];
  $issued_on = $pdf_variables["issued_on"];
  $location_issued = $pdf_variables["location_issued"];
  $receipt_number = $pdf_variables["receipt_number"];
  $displayname = $pdf_variables["displayname"];
  $address_line_1 = $pdf_variables["address_line_1"];
  $address_line_1b = $pdf_variables["address_line_1b"];
  $address_line_2 = $pdf_variables["address_line_2"];
  $address_line_3 = $pdf_variables["address_line_3"];
  $inkind_values = $pdf_variables["inkind_values"];
  $display_year = $pdf_variables["display_year"];
  $issue_type = $pdf_variables["issue_type"];
  $receipt_contributions = $pdf_variables['receipt_contributions'];
  $receipt_status = $pdf_variables['receipt_status'];
 
  $address = _getaddress($contact_id);
  $street_address = $address['street_address'];
  $supplemental_address_1 = $address['supplemental_address_1'];
  $supplemental_address_2 = $address['supplemental_address_2'];
  $supplemental_address_3 = $address['supplemental_address_3'];
  $postal_code = $address['postal_code'];
  $city = $address['city'];
  $country = $address['country'];
  //Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > pdf_variables : '.print_r($pdf_variables,1));
  
  //Format date 2023-12-01 en 01/12/2023
  $issued_on = CRM_Cdntaxreceipts_Utils_MK::date_format_fr($issued_on); //substr($issued_on,8,2).'/'.substr($issued_on,5,2).'/'.substr($issued_on,0,4);

  // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > pdf_variables : '.print_r($pdf_variables,1));

  // Middle center section
  if ($mode == CDNTAXRECEIPTS_MODE_PREVIEW) {
     $pdf->Image($pdf_img_files_path . 'brouillon_mode.jpg', $mymargin_left + 100, $mymargin_top, '', 45);     
  }
  else if ($receipt_status == 'cancelled') {
    $pdf->Image($pdf_img_files_path . 'cancelled_trans.png', $mymargin_left + 65, $mymargin_top, '', 45);
  }
  else if ($is_duplicate) {
    $pdf->Image($pdf_img_files_path . 'duplicate_trans.png', $mymargin_left + 65, $mymargin_top, '', 45);
  }
  
  $fontFNE = 'OpenSans';
   
  // *******************************
  //      N°ORDRE DU RECU
  // *******************************
  $x_detailscolumn = 164;
  $y_detailscolumnstart = 6.4;
  $pdf->SetFont($fontFNE, '', 8.5); 
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0);
  $pdf->Cell(24 ,6, E::ts("%1", array(1 => $receipt_number, 'domain' => 'org.civicrm.cdntaxreceipts')),0,0,'L',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
  //$pdf->Write(10, E::ts("%1", array(1 => $receipt_number, 'domain' => 'org.civicrm.cdntaxreceipts')));
  //$pdf->SetFont($fontFNE, '', 10.5);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 4.4);
  $pdf->Cell(24 ,6, E::ts("%1", array(1 => $contact_id)),0,0,'L',FALSE,'');

  // *******************************
  //      ENCART ADRESSE
  // *******************************
  $x_detailscolumn = 100;
  $y_detailscolumnstart = 42;

  // $addresse_string = '';
  // if (!empty($supplemental_address_1)) $addresse_string .=  $pdf->ln(10).$supplemental_address_1;
  // if (!empty($supplemental_address_2)) $addresse_string .=  $pdf->ln(10).$supplemental_address_2;
  // if (!empty($street_address)) $addresse_string .=  $pdf->ln(10).$street_address;
  // $addresse_string .=  $pdf->ln(10).$postal_code.' '.$city;  
  // if (!empty($country)) $addresse_string .= $pdf->ln(10).$country;

  // //mb_strtoupper($displayname);
  // $bloc_address_1 = mb_strtoupper($displayname).$addresse_string;
  // $bloc_address_2 = $displayname.$addresse_string;

  // $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart );
  // $pdf->MultiCell(94, 30, $bloc_address_1, 1, 'C',FALSE); // http://www.fpdf.org/en/doc/multicell.htm

  $niv = 1;
  $address_line_1 = '';
  $address_line_2 = '';
  $address_line_3 = '';
  $address_line_4 = '';
  $address_line_5 = '';
  if (!empty($supplemental_address_1)) {
    $address_line_1 = $supplemental_address_1;
    $niv ++;
  }
  if (!empty($supplemental_address_2)) {
    switch ($niv){
      case 1 : $address_line_1 = $supplemental_address_2; $niv++; break;
      case 2 : $address_line_2 = $supplemental_address_2; $niv++; break;
    }
  }
  if(!empty($street_address)){
    switch ($niv){
      case 1 : $address_line_1 = $street_address; $niv++; break;
      case 2 : $address_line_2 = $street_address; $niv++; break;
      case 3 : $address_line_3 = $street_address; $niv++; break;
    }
  }
  switch ($niv){
    case 1 : $address_line_1 = $postal_code.' '.$city; $niv++; break;
    case 2 : $address_line_2 = $postal_code.' '.$city; $niv++; break;
    case 3 : $address_line_3 = $postal_code.' '.$city;  $niv++; break;
    case 4 : $address_line_4 = $postal_code.' '.$city;  $niv++; break;
  }
  if (!empty($country) ){
    if (mb_strtoupper($country) == 'FRANCE'){

    }else{
      switch ($niv){
        case 1 : $address_line_1 = $country; $niv++; break;
        case 2 : $address_line_2 = $country; $niv++; break;
        case 3 : $address_line_3 = $country; $niv++; break;
        case 4 : $address_line_4 = $country; $niv++; break;
        case 5 : $address_line_5 = $country; $niv++; break;
      }
    }
  }
  

  $yplus = -1;
  $yinterligne = 6;
  $fontzise = 12;
  if (strlen($displayname) > 35) {   $fontzise = 6; $yinterligne -= 1;}
  $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  $yplus++;
  if (strlen($displayname) > 73 ){
    $fontzise -= 1.5; // $yinterligne -= 1;
    $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  }
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
  $pdf->Write(10, mb_strtoupper($displayname), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  if (strlen($displayname) > 73 ){
    $fontzise += 1.5; // $yinterligne += 1;
    $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  }

  if (!empty($address_line_1)){
    $yplus++;
    if (strlen($address_line_1) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
    $pdf->Write(10, mb_strtoupper($address_line_1), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_1) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }

  }
  if (!empty($address_line_2)){
    $yplus++;
    if (strlen($address_line_2) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_2), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_2) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
  
  }
  if (!empty($address_line_3)){
    $yplus++;
   // $pdf = _display_line_address($pdf, $fontFNE, $font_size, $address_line_3, $mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
    if (strlen($address_line_3) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_3), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_3) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
  }
  if (!empty($address_line_4)){
    $yplus++;
    if (strlen($address_line_4) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_4), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_4) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }

  }
  if (!empty($address_line_5)){
    $yplus++;
    if (strlen($address_line_5) > 73 ){
      $fontzise -= 1.5; // $yinterligne -= 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, mb_strtoupper($address_line_5), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
    if (strlen($address_line_5) > 73 ){
      $fontzise += 1.5; // $yinterligne += 1;
      $pdf->SetFont($fontFNE, '', $fontzise, '', true);
    }

  }

  // ***************************************
  //    ENCART COORDONNEES DU MECENE
  // ***************************************
  $x_detailscolumn = 1.5;
  $y_detailscolumnstart = 136;
  $yplus = -1;
  $yinterligne = 4.5;
  $fontzise = 10;
  $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  $yplus++;
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
  //$pdf->Cell(24 ,9, $displayname,1,0,'L',FALSE,'');
  if (strlen($displayname) > 35) { $pdf->SetFont($fontFNE, '', 7, '', true); $yinterligne -= 1;}
  $pdf->Write(10, $displayname, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  if (!empty($address_line_1)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));
    $pdf->Write(10, $address_line_1, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_2)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_2, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_3)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_3, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_4)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_4, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }
  if (!empty($address_line_5)){
    $yplus++;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
    $pdf->Write(10, $address_line_5, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  }

  // *******************************
  //      ENCART MONTANT
  // *******************************
  $x_detailscolumn = 96;
  $y_detailscolumnstart = 96.5;
  // $amount = '494 494,94';
  // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > pdf_variables : '.print_r($pdf_variables,1));
  $convert = new CRM_Cdntaxreceipts_Utils_ConvertNum($amount, 'EUR');
  $amount = $convert->getFormated(" ", "," );
  $amount = str_replace(' EUR', $espace_incecable.'€', $amount );
  $pdf->SetFont($fontFNE, '', 18);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 23); //25
  // $pdf->Cell(94 ,9,  '***'.mb_strtoupper($amount).'***',0,0,'C',FALSE,''); //http://www.fpdf.org/en/doc/cell.htm
  $pdf->Write(10, '***'.mb_strtoupper($amount).'***', '', 0, 'C', TRUE, 0, FALSE, FALSE, 0);
  
  // *** Display amount_letter ***
  // $convert = new CRM_Cdntaxreceipts_Utils_ConvertNum(str_replace(',','.',$amount), 'EUR');
  $amount_letter = $convert->convert("fr-FR");
  $amount_letter = mb_strtoupper(substr($amount_letter,0,1)).substr($amount_letter,1);
  $amount_letter_array = CRM_Cdntaxreceipts_Utils_MK::cutStringByWord($amount_letter,58);
  $font_size = 10;
  $pdf->SetFont($fontFNE, '', $font_size);
  $iarr = 0;
  $displayAmountLetter = '';
  foreach($amount_letter_array as $value){
    $iarr +=1;
    $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 26.5 + ($iarr*4));
    $pdf->Write(5, $value, '', 0, 'C', FALSE, 0, FALSE, FALSE, 0);
    //$displayAmountLetter .= $pdf->ln(10).$value;   
  }
  
 
  foreach($receipt_contributions as $rc){

    $contribution_id = $rc['contribution_id'];
    $date_don = $rc['receive_date'];
    $date_don = CRM_Cdntaxreceipts_Utils_MK::date_format_fr($date_don); //substr($date_don,8,2).'/'.substr($date_don,5,2).'/'.substr($date_don,0,4);
    $valeur_don = $amount;

    $contributions = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contact_id', 'receive_date', 'total_amount', 'source', 'financial_type_id:label', 'contribution_status_id:label', 'payment_instrument_id:label', 'payment_instrument_id:description')
      ->addWhere('id', '=', $contribution_id)
      ->execute();
    foreach ($contributions as $contribution) {
    // Civi::log()->info('cdntaxreceipts_cdntaxreceipts_writeReceipt _writeReceipt > contribution : '.print_r($contribution,1));
      $num_ligne ++;
      $don_id = $contribution['id'];

      $don_mtt = $contribution['total_amount'];
      $convert = new CRM_Cdntaxreceipts_Utils_ConvertNum(str_replace(',','.',$don_mtt), 'EUR');
      $don_mtt = $convert->getFormated(" ", "," );
      $don_mtt = str_replace(' EUR', $espace_incecable.'€', $don_mtt );
      $valeur_don = $don_mtt;

      $don_mode = $contribution['payment_instrument_id:label']; 
      $don_nature = $contribution['payment_instrument_id:description']; // TODO  $contribution['total_amount']; // 'Numéraire', 'Don en nature', Autre
      $don_mode_code = $contribution['payment_instrument_id:label']; //
      
      $don_affectation = '';
      $don_affectation_code = $contribution['source'];
      $optionValues = \Civi\Api4\OptionValue::get(FALSE)
        ->addWhere('option_group_id:name', '=', 'fne_type_affectation')
        ->addWhere('value', '=', $don_affectation_code)
        ->execute();
      foreach ($optionValues as $optionValue) {
        $don_affectation = $optionValue['label'];
      }
      if (empty($don_affectation)){
        $optionValues = \Civi\Api4\OptionValue::get(FALSE)
          ->addWhere('option_group_id:name', '=', 'fne_type_affectation')
          ->addWhere('value', '=', 'default')
          ->execute();
        foreach ($optionValues as $optionValue) {
          $don_affectation = $optionValue['label'];
        }
      }
      $don_date = CRM_Cdntaxreceipts_Utils_MK::date_format_fr( $contribution['receive_date']); 
    }
  }

  $font_size = 10;
  // ***************************************
  //    N° ORDRE
  // ***************************************
  $x_detailscolumn = 129; // 125;
  $y_detailscolumnstart = 154.2;// 143.4;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($don_id), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  $font_size = 10;
  // ***************************************
  //    VALEUR DU DON
  // ***************************************  
  $x_detailscolumn = 122.6; // 119.8;
  $y_detailscolumnstart = 160.8; // 150.2;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($valeur_don), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  // ***************************************
  //   NATURE
  // ***************************************  
  $x_detailscolumn = 110.4;// 108;
  $y_detailscolumnstart = 167; // 156.6;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($don_nature), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  
  // ***************************************
  //   MODE
  // ***************************************  
  $x_detailscolumn = 109; //107.2;
  $y_detailscolumnstart = 173.2; // 162.6;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($don_mode), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  // ***************************************
  //   DATE
  // ***************************************  
  $x_detailscolumn = 105.4; // 104;
  $y_detailscolumnstart = 180;// 169.6;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($don_date), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  // ***************************************
  //   AFFECTATION
  // ***************************************  
  $x_detailscolumn = 117.4;// 115.6;
  $y_detailscolumnstart = 186.6; // 176.2;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($don_affectation), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  // ***************************************
  //   FAIT à PAris, Le
  // ***************************************  
  $font_size = 12;
  $x_detailscolumn = 126; //117;
  $y_detailscolumnstart = 234.4; // 226.4;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($issued_on), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  // ***************************************
  //   SIREN
  // ***************************************  
  $font_size = 10;
  $siren = '123456789';
  $x_detailscolumn = 18.8; // 14.8;
  $y_detailscolumnstart = 175.4; // 174.8;
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  $pdf->Write(10, ($siren), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  // // ***************************************
  // //   TVA
  // // ***************************************  
  // $tva = 'FRXX123456789';
  // $x_detailscolumn = 54; // 41;
  // $y_detailscolumnstart = 180; // 179.6;
  // $pdf->SetFont($fontFNE, '', $font_size);
  // $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  // $pdf->Write(10, ($tva), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  // ***************************************
  //   FORME JURIDIQUE
  // ***************************************  
  $fj = 'organisme de placement collectif en valeurs mobilières sans personnalité morale';
  $x_detailscolumn = 32; // 24.6;
  $y_detailscolumnstart = 180; // 184.8; // 184.2;
  // $pdf->SetFont($fontFNE, '', 10);
  // $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8);
  // $pdf->Write(10, ($fj), '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);

  $fj_letter_array = CRM_Cdntaxreceipts_Utils_MK::cutStringByWord($fj,35);
  $fj_1erligne = $fj_letter_array[0];
  $fj_letter_array = CRM_Cdntaxreceipts_Utils_MK::cutStringByWord(str_replace($fj_1erligne,'',$fj),60);
  $font_size = 10;
  $iarr = 0;
  // 1ere ligne
  $pdf->SetFont($fontFNE, '', $font_size);
  $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8 + ($iarr*5));
  $pdf->Write(10, $fj_1erligne, '', 0, 'L', FALSE, 0, FALSE, FALSE, 0);

  
  $displayAmountLetter = '';
  foreach($fj_letter_array as $value){
    $iarr +=1;
    // if ($iarr == 0){
    //   $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8 + ($iarr*5));
    // } else {
      $x_detailscolumn = 1.3;
      $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + 0.8 + ($iarr*5));
    // }
    
    $pdf->Write(10, $value, '', 0, 'L', FALSE, 0, FALSE, FALSE, 0);
    //$displayAmountLetter .= $pdf->ln(10).$value;   
  }


}

function _display_line_address(&$pdf, &$fontFNE, &$font_size, $s_address_line, $x, $y){
  if (strlen($s_address_line) > 73 ){
    $fontzise -= 1.5; // $yinterligne -= 1;
    $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  }
 // $pdf->SetXY($mymargin_left + $x_detailscolumn, $mymargin_top + $y_detailscolumnstart + ($yinterligne*$yplus));  
  $pdf->SetXY($x, $y);  
  $pdf->Write(10, $s_address_line, '', 0, 'L', TRUE, 0, FALSE, FALSE, 0);
  if (strlen($s_address_line) > 73 ){
    $fontzise += 1.5; // $yinterligne += 1;
    $pdf->SetFont($fontFNE, '', $fontzise, '', true);
  }

  return $pdf;
}

function _getaddress($contact_id) {
  $address_result = NULL;
  // get Address information via contact
  $addresses = \Civi\Api4\Address::get(FALSE)
    ->addSelect('*', 'country_id:label')
    ->addWhere('contact_id', '=', $contact_id)
    ->addWhere('is_billing', '=', TRUE)
    ->execute();
  foreach ($addresses as $address) {
    $address_result = $address;
  }

  
  if (!isset($address_result)) {
  
    $addresses = \Civi\Api4\Address::get(FALSE)
      ->addSelect('*', 'country_id:label')
      ->addWhere('contact_id', '=', $contact_id)
      ->addWhere('is_primary', '=', TRUE)
      ->execute();
    foreach ($addresses as $address) {
      $address_result = $address;
    }
  }
  $address_result = isset($address_result) ? $address : array();

 

  return $address_result;
}