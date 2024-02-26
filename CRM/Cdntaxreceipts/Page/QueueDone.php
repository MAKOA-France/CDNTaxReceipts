<?php
use CRM_Cdntaxreceipts_ExtensionUtil as E;

class CRM_Cdntaxreceipts_Page_QueueDone extends CRM_Core_Page {

  public function run() {

    CRM_Utils_System::setTitle(E::ts('Your tax receipts have been processed'));
    
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

    $this->assign('qid', $queueId);
    $this->assign('statistics', $metadata['count']);
    $this->assign('preview', $previewMode);

    parent::run();
  }

}
