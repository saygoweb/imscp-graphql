<div class="static_info">
    <h2>{TR_CONFIG_TITLE}</h2>
    <table class="firstColFixed">
        <thead>
        <tr><th>{TR_CONFIG_KEY}</th><th>{TR_CONFIG_VALUE}</th></tr>
        </thead>
        <tbody>
        <!-- BDP: config_item -->
        <tr>
            <td><code>{CONFIG_KEY}</code></td>
            <td>{CONFIG_VALUE}</td>
        </tr>
        <!-- EDP: config_item -->
        </tbody>
    </table>
</div>

<div class="static_info">
    <h2>{TR_AUDIT_TITLE}</h2>

    <!-- BDP: no_audit_block -->
    <div class="static_info">{TR_NO_AUDIT}</div>
    <!-- EDP: no_audit_block -->

    <!-- BDP: audit_list -->
    <table class="firstColFixed datatable">
        <thead>
        <tr>
            <th>{TR_AT}</th>
            <th>{TR_ACCOUNT}</th>
            <th>{TR_VIA}</th>
            <th>{TR_IP}</th>
            <th>{TR_OPERATION}</th>
            <th>{TR_FIELDS}</th>
            <th>{TR_OUTCOME}</th>
            <th>{TR_ERROR_CODE}</th>
            <th>{TR_DURATION}</th>
        </tr>
        </thead>
        <tbody>
        <!-- BDP: audit_item -->
        <tr>
            <td>{AUDIT_AT}</td>
            <td>{AUDIT_ACCOUNT}</td>
            <td>{AUDIT_VIA}</td>
            <td>{AUDIT_IP}</td>
            <td>{AUDIT_OPERATION}</td>
            <td>{AUDIT_FIELDS}</td>
            <td><div class="icon i_{AUDIT_OUTCOME_ICON}">{AUDIT_OUTCOME}</div></td>
            <td>{AUDIT_ERROR_CODE}</td>
            <td>{AUDIT_DURATION}</td>
        </tr>
        <!-- EDP: audit_item -->
        </tbody>
    </table>

    <div class="buttons">
        <a class="icon i_back{AUDIT_PREV_DISABLED}" href="?page={AUDIT_PREV_PAGE}">{TR_PREV}</a>
        <span>{AUDIT_PAGE} / {AUDIT_TOTAL_PAGES} ({AUDIT_TOTAL})</span>
        <a class="icon i_next{AUDIT_NEXT_DISABLED}" href="?page={AUDIT_NEXT_PAGE}">{TR_NEXT}</a>
    </div>
    <!-- EDP: audit_list -->
</div>
