<div class="info">{TR_INTRO}</div>

<!-- BDP: new_token_block -->
<div class="success">
    <p>{TR_NEW_TOKEN_INTRO}</p>
    <pre style="user-select:all;padding:.6em;overflow-x:auto">{NEW_TOKEN}</pre>
    <p><strong>{TR_NEW_TOKEN_WARNING}</strong></p>
</div>
<!-- EDP: new_token_block -->

<!-- BDP: no_tokens_block -->
<div class="static_info">{TR_NO_TOKENS}</div>
<!-- EDP: no_tokens_block -->

<!-- BDP: token_list -->
<table class="firstColFixed datatable">
    <thead>
    <tr>
        <th>{TR_STATE}</th>
        <th>{TR_NAME}</th>
        <th>{TR_PREFIX}</th>
        <th>{TR_SCOPES}</th>
        <th>{TR_CREATED}</th>
        <th>{TR_EXPIRES}</th>
        <th>{TR_LAST_USED}</th>
        <th>{TR_ACTION}</th>
    </tr>
    </thead>
    <tbody>
    <!-- BDP: token_item -->
    <tr>
        <td><div class="icon i_{STATE_ICON}">{STATE}</div></td>
        <td>{NAME}</td>
        <td><code>{PREFIX}</code></td>
        <td>{SCOPES}</td>
        <td>{CREATED}</td>
        <td>{EXPIRES}</td>
        <td>{LAST_USED}</td>
        <td>
            <!-- BDP: revoke_action -->
            <form method="post" action="api_tokens.php" style="display:inline">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="id" value="{TOKEN_ID}">
                <input type="hidden" name="csrf" value="{CSRF_TOKEN}">
                <button type="submit" class="icon i_delete"
                        onclick="return confirm('{TR_REVOKE_CONFIRM}');">{TR_REVOKE}</button>
            </form>
            <!-- EDP: revoke_action -->
        </td>
    </tr>
    <!-- EDP: token_item -->
    </tbody>
</table>
<!-- EDP: token_list -->

<form method="post" action="api_tokens.php">
    <input type="hidden" name="csrf" value="{CSRF_TOKEN}">
    <table class="firstColFixed">
        <thead>
        <tr><th colspan="2">{TR_CREATE}</th></tr>
        </thead>
        <tbody>
        <tr>
            <td><label for="name">{TR_NAME}</label></td>
            <td><input type="text" name="name" id="name" value="{NAME_VALUE}" maxlength="255"></td>
        </tr>
        <tr>
            <td><label for="ttl_days">{TR_LIFETIME}</label></td>
            <td>
                <input type="number" name="ttl_days" id="ttl_days" min="1" max="{MAX_TTL}"
                       value="{TTL_VALUE}"> {TR_DAYS}
            </td>
        </tr>
        <tr>
            <td>{TR_SCOPES}</td>
            <td>
                <!-- BDP: scope_item -->
                <label style="display:inline-block;min-width:14em">
                    <input type="checkbox" name="scopes[]" value="{SCOPE}"{SCOPE_CHECKED}>
                    {SCOPE}
                </label>
                <!-- EDP: scope_item -->
                <p class="hint">{TR_SCOPES_HINT}</p>
            </td>
        </tr>
        <tr>
            <td><label for="ip_allowlist">{TR_IP_ALLOWLIST}</label></td>
            <td>
                <input type="text" name="ip_allowlist" id="ip_allowlist"
                       value="{IP_ALLOWLIST_VALUE}" placeholder="10.0.0.0/8, 203.0.113.5">
                <p class="hint">{TR_IP_ALLOWLIST_HINT}</p>
            </td>
        </tr>
        </tbody>
    </table>
    <div class="buttons">
        <input name="submit" type="submit" value="{TR_CREATE}">
    </div>
</form>

<div class="static_info">
    <p>{TR_ENDPOINT}: <code>{ENDPOINT}</code></p>
    <p>{TR_SCHEMA}: <a href="{SCHEMA_LINK}"><code>{SCHEMA_ENDPOINT}</code></a></p>
</div>
