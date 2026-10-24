{* DNA SSL - client area. Every value from the gateway or the client is escaped. *}
{assign var=base value="clientarea.php?action=productdetails&id=`$serviceid`&modop=custom&a="}
{assign var=domain value=$row.domain|default:''|escape}

<div class="dnassl">
  {if $error}
    <div class="alert alert-danger">{$error|escape}</div>
  {/if}

  <div class="card panel panel-default mb-3">
    <div class="card-body panel-body">
      <div class="row">
        <div class="col-sm-6">
          <strong>{$L.domain|escape}:</strong> {if $domain}{$domain}{else}-{/if}<br>
          <strong>{$L.status|escape}:</strong>
          {assign var=statusKey value="status_`$status`"}
          <span class="label badge {if $status eq 'active'}label-success bg-success{elseif $status eq 'revoked' or $status eq 'expired'}label-danger bg-danger{else}label-warning bg-warning{/if}">{$L.$statusKey|default:$status|escape}</span>
        </div>
        <div class="col-sm-6">
          {if $detail.certificate.issuedAt}<strong>{$L.issued_at|escape}:</strong> {$detail.certificate.issuedAt|truncate:10:'':true|escape}<br>{/if}
          {if $detail.certificate.validUntil}<strong>{$L.expires_at|escape}:</strong> {$detail.certificate.validUntil|truncate:10:'':true|escape}{/if}
        </div>
      </div>
    </div>
  </div>

  {if $status eq 'awaiting_configuration'}
    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$L.configure_title|escape}</strong></div>
      <div class="card-body panel-body">
        <p>{$L.configure_intro|escape|replace:'%s':$domain}</p>
        <form method="post" action="{$base}configure">
          <input type="hidden" name="token" value="{$token|escape}">

          <div class="form-group mb-3">
            <label for="dnasslCsr">{$L.csr|escape}</label>
            <textarea class="form-control" id="dnasslCsr" name="csr" rows="8" style="font-family:monospace" placeholder="-----BEGIN CERTIFICATE REQUEST-----"></textarea>
            <small class="form-text text-muted">{$L.csr_help|escape}</small>
          </div>

          <div class="checkbox form-check mb-2">
            <label><input type="checkbox" name="generate_csr" value="1" id="dnasslGen"> {$L.generate_csr|escape}</label>
          </div>
          <div id="dnasslGenFields" style="display:none" class="mb-3">
            <p class="text-muted"><small>{$L.generate_csr_help|escape}</small></p>
            <div class="row">
              <div class="col-sm-6 form-group mb-2"><label>{$L.csr_org|escape}</label><input class="form-control" name="csr_org" value="{if $client.companyname}{$client.companyname|escape}{else}{$client.fullname|escape}{/if}"></div>
              <div class="col-sm-6 form-group mb-2"><label>{$L.csr_unit|escape}</label><input class="form-control" name="csr_unit" value="IT"></div>
              <div class="col-sm-4 form-group mb-2"><label>{$L.csr_city|escape}</label><input class="form-control" name="csr_city" value="{$client.city|escape}"></div>
              <div class="col-sm-4 form-group mb-2"><label>{$L.csr_state|escape}</label><input class="form-control" name="csr_state" value="{$client.state|escape}"></div>
              <div class="col-sm-4 form-group mb-2"><label>{$L.csr_country|escape}</label><input class="form-control" name="csr_country" maxlength="2" value="{$client.countrycode|escape}"></div>
            </div>
          </div>

          {if $maxSans > 0}
            <div class="form-group mb-3">
              <label for="dnasslSans">{$L.san_list|escape}</label>
              <textarea class="form-control" id="dnasslSans" name="san_list" rows="3"></textarea>
              <small class="form-text text-muted">{$L.san_help|escape|replace:'%d':$maxSans}</small>
            </div>
          {/if}

          <div class="row">
            <div class="col-sm-4 form-group mb-3">
              <label for="dnasslServer">{$L.web_server|escape}</label>
              <select class="form-control form-select" id="dnasslServer" name="web_server">
                {foreach $webServers as $value => $label}<option value="{$value|escape}">{$label|escape}</option>{/foreach}
              </select>
            </div>
            <div class="col-sm-4 form-group mb-3">
              <label for="dnasslMethod">{$L.dcv_method|escape}</label>
              <select class="form-control form-select" id="dnasslMethod" name="dcv_method">
                <option value="email">{$L.dcv_email|escape}</option>
                <option value="http">{$L.dcv_http|escape}</option>
                <option value="dns">{$L.dcv_dns|escape}</option>
              </select>
            </div>
            <div class="col-sm-4 form-group mb-3" id="dnasslApproverWrap">
              <label for="dnasslApprover">{$L.approver|escape}</label>
              <select class="form-control form-select" id="dnasslApprover" name="approver">
                {foreach $approvers as $mail}<option value="{$mail|escape}">{$mail|escape}</option>{/foreach}
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">{$L.submit|escape}</button>
        </form>
      </div>
    </div>
    <script>
    (function () {
      var gen = document.getElementById('dnasslGen'), box = document.getElementById('dnasslGenFields'),
          csr = document.getElementById('dnasslCsr'), method = document.getElementById('dnasslMethod'),
          appr = document.getElementById('dnasslApproverWrap');
      gen.addEventListener('change', function () { box.style.display = gen.checked ? '' : 'none'; csr.disabled = gen.checked; });
      method.addEventListener('change', function () { appr.style.display = method.value === 'email' ? '' : 'none'; });
    })();
    </script>
  {/if}

  {if $status eq 'processing'}
    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$L.validation_title|escape}</strong></div>
      <div class="card-body panel-body">
        {if $dcv.dns}
          <p>{$L.validation_dns|escape|replace:'%s':$domain}</p>
          <table class="table table-condensed table-sm">
            <tr><th>{$L.dns_host|escape}</th><td><code>{$dcv.dns.host|escape}</code></td></tr>
            <tr><th>{$L.dns_type|escape}</th><td><code>{$dcv.dns.type|escape}</code></td></tr>
            <tr><th>{$L.dns_value|escape}</th><td><code style="word-break:break-all">{$dcv.dns.value|escape}</code></td></tr>
          </table>
        {elseif $dcv.file}
          <p>{$L.validation_file|escape}</p>
          <table class="table table-condensed table-sm">
            <tr><th>{$L.file_name|escape}</th><td><code style="word-break:break-all">{if $dcv.file.link}{$dcv.file.link|escape}{else}{$dcv.file.filename|escape}{/if}</code></td></tr>
            <tr><th>{$L.file_content|escape}</th><td><pre style="white-space:pre-wrap;word-break:break-all">{$dcv.file.content|escape}</pre></td></tr>
          </table>
        {elseif $approverShown}{assign var=apprEsc value=$approverShown|escape}
          <p>{$L.validation_email|escape|replace:'%s':$apprEsc}</p>
        {elseif $dcv.raw}
          <p>{$L.validation_raw|escape}</p>
          <pre style="white-space:pre-wrap;word-break:break-all">{$dcv.raw|escape}</pre>
        {/if}
        <form method="post" action="{$base}refreshStatus" class="mt-2">
          <input type="hidden" name="token" value="{$token|escape}">
          <button type="submit" class="btn btn-default btn-outline-secondary">{$L.check_now|escape}</button>
        </form>
      </div>
    </div>
  {/if}

  {if $status eq 'active'}
    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$L.download_title|escape}</strong></div>
      <div class="card-body panel-body">
        <a class="btn btn-success" href="{$base}downloadCertificate">{$L.download_crt|escape}</a>
        {if $hasKey}
          <a class="btn btn-default btn-outline-secondary" href="{$base}downloadPrivateKey">{$L.download_key|escape}</a>
          <p class="text-muted mt-2"><small>{$L.key_warning|escape}</small></p>
        {/if}
      </div>
    </div>

    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$L.reissue_title|escape}</strong></div>
      <div class="card-body panel-body">
        <p>{$L.reissue_intro|escape}</p>
        <form method="post" action="{$base}reissue">
          <input type="hidden" name="token" value="{$token|escape}">
          <div class="form-group mb-3">
            <label>{$L.reissue_csr|escape}</label>
            <textarea class="form-control" name="csr" rows="6" style="font-family:monospace"></textarea>
          </div>
          <div class="form-group mb-3">
            <label>{$L.approver|escape}</label>
            <select class="form-control form-select" name="approver">
              {foreach $approvers as $mail}<option value="{$mail|escape}"{if $mail eq $row.approver} selected{/if}>{$mail|escape}</option>{/foreach}
            </select>
          </div>
          <button type="submit" class="btn btn-primary">{$L.reissue|escape}</button>
        </form>
      </div>
    </div>
  {/if}
</div>
