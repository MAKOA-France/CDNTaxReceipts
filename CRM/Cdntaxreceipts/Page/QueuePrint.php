<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;

class CRM_Cdntaxreceipts_Page_QueuePrint extends CRM_Core_Page {

  public function run() {
    
    $queueId = CRM_Utils_Request::retrieveValue('queue', 'Positive');
    
    $userJob = \Civi\Api4\UserJob::get(FALSE)
      ->addSelect('metadata')
      ->addWhere('queue_id', '=', $queueId)
      ->setLimit(1)
      ->execute()
      ->first();
    $metadata = $userJob['metadata'];
    $previewMode = $metadata['preview'];
    $year = $metadata['year'];

    // get the pdf of printed
    $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
    $contacts = $metadata['print'];
    foreach ($contacts as $contactId) {
      list($ret, $method) = cdntaxreceipts_issueAnnualTaxReceipt($contactId, $year, $receiptsForPrinting, $previewMode, TRUE);
    }

    cdntaxreceipts_sendCollectedPDF($receiptsForPrinting, CRM_Utils_File::makeFilenameWithUnicode(ts('Receipts-To-Print-%1', [1 => (int) $_SERVER['REQUEST_TIME'], 'domain' => 'org.civicrm.cdntaxreceipts'])) . '.pdf');

  }

}
