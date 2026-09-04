<div class="info">{TR_INTRO}</div>

<!-- BDP: no_customers_block -->
<div class="static_info">{TR_NO_CUSTOMERS}</div>
<!-- EDP: no_customers_block -->

<!-- BDP: customer_list -->
<table class="firstColFixed datatable">
    <thead>
    <tr>
        <th>{TR_ACCESS}</th>
        <th>{TR_CUSTOMER}</th>
        <th>{TR_LIVE_TOKENS}</th>
        <th>{TR_ACTION}</th>
    </tr>
    </thead>
    <tbody>
    <!-- BDP: customer_item -->
    <tr>
        <td><div class="icon i_{ACCESS_ICON}">{ACCESS}</div></td>
        <td>{CUSTOMER_NAME}</td>
        <td>{LIVE_TOKENS}</td>
        <td>
            <form method="post" action="api_access.php" style="display:inline">
                <input type="hidden" name="action" value="{TOGGLE_ACTION}">
                <input type="hidden" name="id" value="{ADMIN_ID}">
                <input type="hidden" name="csrf" value="{CSRF_TOKEN}">
                <button type="submit" class="icon i_{TOGGLE_ICON}"
                        onclick="return confirm('{TOGGLE_CONFIRM}');">{TOGGLE_LABEL}</button>
            </form>
            <!-- BDP: revoke_all_action -->
            <form method="post" action="api_access.php" style="display:inline">
                <input type="hidden" name="action" value="revoke_all">
                <input type="hidden" name="id" value="{ADMIN_ID}">
                <input type="hidden" name="csrf" value="{CSRF_TOKEN}">
                <button type="submit" class="icon i_delete"
                        onclick="return confirm('{TR_REVOKE_ALL_CONFIRM}');">{TR_REVOKE_ALL}</button>
            </form>
            <!-- EDP: revoke_all_action -->
        </td>
    </tr>
    <!-- EDP: customer_item -->
    </tbody>
</table>
<!-- EDP: customer_list -->
