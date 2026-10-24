{* DNA SSL - client area. Every value from the gateway or the client is escaped. *}
{assign var=dnaBase value="clientarea.php?action=productdetails&id=`$dnaServiceid`&modop=custom&a="}
{assign var=dnaDomain value=$dnaRow.domain|default:''|escape}

<div class="dnassl">
  {if $dnaError}
    <div class="alert alert-danger">{$dnaError|escape}</div>
  {/if}

  <div class="card panel panel-default mb-3">
    <div class="card-body panel-body">
      <div class="row">
        <div class="col-sm-6">
          <strong>{$dnaL.domain|escape}:</strong> {if $dnaDomain}{$dnaDomain}{else}-{/if}<br>
          <strong>{$dnaL.status|escape}:</strong>
          {assign var=dnaStatusKey value="status_`$dnaStatus`"}
          <span class="label badge {if $dnaStatus eq 'active'}label-success bg-success{elseif $dnaStatus eq 'revoked' or $dnaStatus eq 'expired'}label-danger bg-danger{else}label-warning bg-warning{/if}">{$dnaL.$dnaStatusKey|default:$dnaStatus|escape}</span>
        </div>
        <div class="col-sm-6">
          {if $dnaIssuedAt}<strong>{$dnaL.issued_at|escape}:</strong> {$dnaIssuedAt|escape}<br>{/if}
          {if $dnaValidUntil}<strong>{$dnaL.expires_at|escape}:</strong> {$dnaValidUntil|escape}{/if}
        </div>
      </div>
    </div>
  </div>

  {if $dnaStatus eq 'awaiting_configuration'}
    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$dnaL.configure_title|escape}</strong></div>
      <div class="card-body panel-body">
        <p>{$dnaL.configure_intro|escape|replace:'%s':$dnaDomain}</p>
        <form method="post" action="{$dnaBase}configure">
          <input type="hidden" name="token" value="{$dnaToken|escape}">

          <div class="form-group mb-3">
            <label for="dnasslCsr">{$dnaL.csr|escape}</label>
            <textarea class="form-control" id="dnasslCsr" name="csr" rows="8" style="font-family:monospace" placeholder="-----BEGIN CERTIFICATE REQUEST-----"></textarea>
            <small class="form-text text-muted">{$dnaL.csr_help|escape}</small>
          </div>

          <div class="checkbox form-check mb-2">
            <label><input type="checkbox" name="generate_csr" value="1" id="dnasslGen"> {$dnaL.generate_csr|escape}</label>
          </div>
          <div id="dnasslGenFields" style="display:none" class="mb-3">
            <p class="text-muted"><small>{$dnaL.generate_csr_help|escape}</small></p>
            <div class="row">
              <div class="col-sm-6 form-group mb-2"><label>{$dnaL.csr_org|escape}</label><input class="form-control" name="csr_org" value="{if $dnaClient.companyname}{$dnaClient.companyname|escape}{else}{$dnaClient.fullname|escape}{/if}"></div>
              <div class="col-sm-6 form-group mb-2"><label>{$dnaL.csr_unit|escape}</label><input class="form-control" name="csr_unit" value="IT"></div>
              <div class="col-sm-4 form-group mb-2"><label>{$dnaL.csr_city|escape}</label><input class="form-control" name="csr_city" value="{$dnaClient.city|escape}"></div>
              <div class="col-sm-4 form-group mb-2"><label>{$dnaL.csr_state|escape}</label><input class="form-control" name="csr_state" value="{$dnaClient.state|escape}"></div>
              <div class="col-sm-4 form-group mb-2"><label>{$dnaL.csr_country|escape}</label><input class="form-control" name="csr_country" maxlength="2" value="{$dnaClient.countrycode|escape}"></div>
            </div>
          </div>

          {if $dnaMaxSans > 0}
            <div class="form-group mb-3">
              <label for="dnasslSans">{$dnaL.san_list|escape}</label>
              <textarea class="form-control" id="dnasslSans" name="san_list" rows="3"></textarea>
              <small class="form-text text-muted">{$dnaL.san_help|escape|replace:'%d':$dnaMaxSans}</small>
            </div>
          {/if}

          <div class="row">
            <div class="col-sm-4 form-group mb-3">
              <label for="dnasslServer">{$dnaL.web_server|escape}</label>
              <select class="form-control form-select" id="dnasslServer" name="web_server">
                {foreach $dnaWebServers as $value => $label}<option value="{$value|escape}">{$label|escape}</option>{/foreach}
              </select>
            </div>
            <div class="col-sm-4 form-group mb-3">
              <label for="dnasslMethod">{$dnaL.dcv_method|escape}</label>
              <select class="form-control form-select" id="dnasslMethod" name="dcv_method">
                <option value="email">{$dnaL.dcv_email|escape}</option>
                <option value="http">{$dnaL.dcv_http|escape}</option>
                <option value="dns">{$dnaL.dcv_dns|escape}</option>
              </select>
            </div>
            <div class="col-sm-4 form-group mb-3" id="dnasslApproverWrap">
              <label for="dnasslApprover">{$dnaL.approver|escape}</label>
              <select class="form-control form-select" id="dnasslApprover" name="approver">
                {foreach $dnaApprovers as $mail}<option value="{$mail|escape}">{$mail|escape}</option>{/foreach}
              </select>
            </div>
          </div>

          <button type="submit" class="btn btn-primary">{$dnaL.submit|escape}</button>
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

  {if $dnaStatus eq 'processing'}
    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$dnaL.validation_title|escape}</strong></div>
      <div class="card-body panel-body">
        {if $dnaDcv.dns}
          <p>{$dnaL.validation_dns|escape|replace:'%s':$dnaDomain}</p>
          <table class="table table-condensed table-sm">
            <tr><th>{$dnaL.dns_host|escape}</th><td><code>{$dnaDcv.dns.host|escape}</code></td></tr>
            <tr><th>{$dnaL.dns_type|escape}</th><td><code>{$dnaDcv.dns.type|escape}</code></td></tr>
            <tr><th>{$dnaL.dns_value|escape}</th><td><code style="word-break:break-all">{$dnaDcv.dns.value|escape}</code></td></tr>
          </table>
        {elseif $dnaDcv.file}
          <p>{$dnaL.validation_file|escape}</p>
          <table class="table table-condensed table-sm">
            <tr><th>{$dnaL.file_name|escape}</th><td><code style="word-break:break-all">{if $dnaDcv.file.link}{$dnaDcv.file.link|escape}{else}{$dnaDcv.file.filename|escape}{/if}</code></td></tr>
            <tr><th>{$dnaL.file_content|escape}</th><td><pre style="white-space:pre-wrap;word-break:break-all">{$dnaDcv.file.content|escape}</pre></td></tr>
          </table>
        {elseif $dnaApproverShown}{assign var=dnaApprEsc value=$dnaApproverShown|escape}
          <p>{$dnaL.validation_email|escape|replace:'%s':$dnaApprEsc}</p>
        {elseif $dnaDcv.raw}
          <p>{$dnaL.validation_raw|escape}</p>
          <pre style="white-space:pre-wrap;word-break:break-all">{$dnaDcv.raw|escape}</pre>
        {/if}
        <form method="post" action="{$dnaBase}refreshStatus" class="mt-2">
          <input type="hidden" name="token" value="{$dnaToken|escape}">
          <button type="submit" class="btn btn-default btn-outline-secondary">{$dnaL.check_now|escape}</button>
        </form>
      </div>
    </div>
  {/if}

  {if $dnaStatus eq 'active'}
    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$dnaL.download_title|escape}</strong></div>
      <div class="card-body panel-body">
        <a class="btn btn-success" href="{$dnaBase}downloadCertificate">{$dnaL.download_crt|escape}</a>
        {if $dnaHasKey}
          <a class="btn btn-default btn-outline-secondary" href="{$dnaBase}downloadPrivateKey">{$dnaL.download_key|escape}</a>
          <p class="text-muted mt-2"><small>{$dnaL.key_warning|escape}</small></p>
        {/if}
      </div>
    </div>

    <div class="card panel panel-default mb-3">
      <div class="card-header panel-heading"><strong>{$dnaL.reissue_title|escape}</strong></div>
      <div class="card-body panel-body">
        <p>{$dnaL.reissue_intro|escape}</p>
        <form method="post" action="{$dnaBase}reissue">
          <input type="hidden" name="token" value="{$dnaToken|escape}">
          <div class="form-group mb-3">
            <label>{$dnaL.reissue_csr|escape}</label>
            <textarea class="form-control" name="csr" rows="6" style="font-family:monospace"></textarea>
          </div>
          <div class="form-group mb-3">
            <label>{$dnaL.approver|escape}</label>
            <select class="form-control form-select" name="approver">
              {foreach $dnaApprovers as $mail}<option value="{$mail|escape}"{if $mail eq $dnaRow.approver} selected{/if}>{$mail|escape}</option>{/foreach}
            </select>
          </div>
          <button type="submit" class="btn btn-primary">{$dnaL.reissue|escape}</button>
        </form>
      </div>
    </div>
  {/if}
</div>
