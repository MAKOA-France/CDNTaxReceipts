<?php

class CRM_Cdntaxreceipts_Utils {
  
  static function processOneReceipt($contactId, $year, $previewMode, $queueName) {

    list( $issuedOn, $receiptId ) = cdntaxreceipts_annual_issued_on($contactId, $year);
      $contributions = cdntaxreceipts_contributions_not_receipted($contactId, $year);

    if ( empty($issuedOn) && count($contributions) > 0 ) {

      $userJob = \Civi\Api4\UserJob::get(TRUE)
        ->addSelect('metadata')
        ->addWhere('name', '=', $queueName)
        ->setLimit(1)
        ->execute()
        ->first();
      $metadata = $userJob['metadata'];

      // start a PDF to collect receipts that cannot be emailed
      $receiptsForPrinting = cdntaxreceipts_openCollectedPDF();
      list( $ret, $method ) = cdntaxreceipts_issueAnnualTaxReceipt($contactId, $year, $receiptsForPrinting, $previewMode);

      if ( $ret == 0 ) {
        $metadata['count']['fail']++;
      }
      elseif ( $method == 'email' ) {
        $metadata['count']['email']++;
      }
      elseif ( $method == 'print') {
        $metadata['count']['print']++;

        // TODO: add contactId to metadata to compute the pdf to print at the end
        if (!isset($metadata['print'])) $metadata['print'] = [];
        $metadata['print'][] = $contactId;
      }
      elseif ( $method == 'data') {
        $dataCount++;
        $metadata['count']['data']
      }

      $results = \Civi\Api4\UserJob::update(TRUE)
        ->addValue('metadata', $metadata)
        ->addWhere('name', '=', $queueName)
        ->execute();

    }

    sleep(2);
    return TRUE;
  }

}
