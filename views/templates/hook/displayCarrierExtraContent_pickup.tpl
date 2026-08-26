<div class="ppomniva-pickup"
     data-has-server-list="{if $ppomniva_terminals_by_city}1{else}0{/if}"
     data-ajax-url="{$ppomniva_ajax_url}"
     data-country="{$ppomniva_country_code}"
     data-postcode="{$ppomniva_postcode|escape:'html':'UTF-8'}"
     data-carrier-id="{$ppomniva_carrier_id}"
     data-i18n="{$ppomniva_i18n|escape:'html':'UTF-8'}">

    {* Top toolbar — nearest-by-postcode only. Free-text filtering lives in
       the list search bar above the scrollable list, where it's right next
       to the rows being filtered. *}
    <div class="ppomniva-pickup__toolbar"{if $ppomniva_selected_terminal} style="display:none"{/if}>
        <div class="ppomniva-pickup__nearest">
            <input type="text" class="ppomniva-pickup__nearest-input js-ppomniva-nearest-input"
                   value="{$ppomniva_postcode|escape:'html':'UTF-8'}"
                   placeholder="{l s='Postcode' d='Modules.Ppomniva.Shop' mod='ppomniva'}"
                   maxlength="12" />
            <button type="button" class="ppomniva-pickup__nearest-btn js-ppomniva-nearest-btn">
                {l s='Find nearest' d='Modules.Ppomniva.Shop' mod='ppomniva'}
            </button>
            <span class="ppomniva-pickup__nearest-status js-ppomniva-nearest-status" aria-live="polite"></span>
        </div>
    </div>

    {* Selected terminal display *}
    <div class="ppomniva-pickup__selected js-ppomniva-selected" {if !$ppomniva_selected_terminal}style="display:none"{/if}>
        <div class="ppomniva-pickup__selected-icon">&#10003;</div>
        <div class="ppomniva-pickup__selected-info">
            <strong class="js-ppomniva-selected-name">{if $ppomniva_selected_terminal}{$ppomniva_selected_terminal.info.name}{/if}</strong>
            <span class="js-ppomniva-selected-address">{if $ppomniva_selected_terminal}{$ppomniva_selected_terminal.info.address}, {$ppomniva_selected_terminal.info.city}{/if}</span>
        </div>
        <button type="button" class="ppomniva-pickup__change-btn js-ppomniva-change">
            {l s='Change' d='Modules.Ppomniva.Shop' mod='ppomniva'}
        </button>
    </div>

    {* Baseline picker: a real, server-rendered form control listing every
       pickup point. This is what makes the selector visible no matter what
       happens afterwards — blocked assets, a checkout that never runs our JS,
       an unreachable module route. checkout.js hides and disables it once the
       map + filterable list below have initialised. *}
    {if $ppomniva_terminals_by_city}
        <div class="ppomniva-pickup__basic js-ppomniva-basic"{if $ppomniva_selected_terminal} style="display:none"{/if}>
            <label class="ppomniva-pickup__basic-label" for="ppomniva-terminal-select">
                {l s='Select a pickup point' d='Modules.Ppomniva.Shop' mod='ppomniva'}
            </label>
            <select id="ppomniva-terminal-select"
                    name="ppomniva_terminal_select"
                    class="ppomniva-pickup__basic-select js-ppomniva-basic-select">
                <option value="">{l s='Select a pickup point' d='Modules.Ppomniva.Shop' mod='ppomniva'}</option>
                {foreach $ppomniva_terminals_by_city as $city => $cityTerminals}
                    <optgroup label="{$city|escape:'html':'UTF-8'}">
                        {foreach $cityTerminals as $terminal}
                            <option value="{$terminal.terminal_id|escape:'html':'UTF-8'}"{if $ppomniva_selected_terminal && $ppomniva_selected_terminal.id == $terminal.terminal_id} selected="selected"{/if}>{if $terminal.display_name}{$terminal.display_name|escape:'html':'UTF-8'}{else}{$terminal.name|escape:'html':'UTF-8'}{/if}{if $terminal.address}, {$terminal.address|escape:'html':'UTF-8'}{/if}</option>
                        {/foreach}
                    </optgroup>
                {/foreach}
            </select>
        </div>
    {/if}

    {* Terminal payload for checkout.js — already resolved server-side, so the
       enhanced UI needs no AJAX round trip to draw itself. *}
    <script type="application/json" class="js-ppomniva-terminals">{$ppomniva_terminals_json nofilter}</script>

    {* Map container *}
    <div class="ppomniva-pickup__map-container js-ppomniva-map-wrap" {if $ppomniva_selected_terminal}style="display:none"{/if}>
        <div id="ppomniva-map" class="ppomniva-pickup__map"></div>
    </div>

    {* Terminal list (below map) *}
    <div class="ppomniva-pickup__list js-ppomniva-list-wrap" {if $ppomniva_selected_terminal}style="display:none"{/if}>
        <div class="ppomniva-pickup__list-search">
            <input type="text" class="ppomniva-pickup__list-search-input js-ppomniva-list-search"
                   placeholder="{l s='Filter terminals — name, city or address' d='Modules.Ppomniva.Shop' mod='ppomniva'}" />
        </div>
        <div class="ppomniva-pickup__list-items js-ppomniva-list">
            {* Populated via JS *}
        </div>
    </div>

    {* Hidden input for validation *}
    <input type="hidden" name="ppomniva_terminal_id" class="js-ppomniva-terminal-id"
           value="{if $ppomniva_selected_terminal}{$ppomniva_selected_terminal.id}{/if}" />

    {* Error message area *}
    <div class="ppomniva-pickup__error js-ppomniva-error" style="display:none"></div>
</div>
