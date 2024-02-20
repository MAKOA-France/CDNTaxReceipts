{crmScope extensionKey='org.civicrm.cdntaxreceipts'}

<div class="messages status no-popup">
  <div class="icon inform-icon"></div>
  {ts 1=$totalSelectedContacts}You have selected <strong>%1</strong> contacts.{/ts}
</div>

{if $preview}
  <div class="help info">{ts 1=0}%1 tax receipt(s) have been previewed.  No receipts have been issued.{/ts}</div>
{/if}

{if $statistics.fail > 0}
  <div class="help warning">{ts 1=$statistics.fail}%1 tax receipt(s) failed to process.{/ts}</div>
{/if}

<div>
  <p>Please find below the processing details :</p>
  <ul>
    <li>{ts 1=$statistics.email}%1 tax receipt(s) were sent by email.{/ts}</li>
    <li>{ts 1=$statistics.print}%1 tax receipt(s) need to be printed.{/ts}. <a href="/civicrm/cdntaxreceipts/queue-print?queue={$qid}">Download the PDF</a></li>
    <li>{ts 1=$statistics.data}Data for %1 tax receipt(s) is available in the Tax Receipts Issued report.{/ts}</li>
  </ul>
</div>

{/crmScope}
