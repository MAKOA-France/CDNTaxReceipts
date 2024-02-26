<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;

class CRM_Cdntaxreceipts_Page_QueuePrint extends CRM_Core_Page {

  public function run() {
    
    // required
    $queueId = CRM_Utils_Request::retrieveValue('queue', 'Positive', NULL, TRUE);
    
    // get all receipts that were created for printing and put them in a big file
    $receiptsForPrinting = CRM_Cdntaxreceipts_Utils_Queue::getAllCollectedPdfFilename($queueId);

    if ($receiptsForPrinting) {
      cdntaxreceipts_sendCollectedPDF($receiptsForPrinting, CRM_Utils_File::makeFilenameWithUnicode(E::ts('Receipts-To-Print-%1', [1 => (int) $_SERVER['REQUEST_TIME']])) . '.pdf');
    }

  }

}
