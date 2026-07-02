<div class="ppomniva-courier" data-ajax-url="{$ppomniva_ajax_url}" data-carrier-id="{$ppomniva_carrier_id}">

    {if $ppomniva_show_door_code}
    <div class="ppomniva-courier__field">
        <label for="ppomniva-door-code">{l s='Door code' d='Modules.Ppomniva.Shop' mod='ppomniva'}</label>
        <input type="text" id="ppomniva-door-code" name="ppomniva_door_code" maxlength="10"
               class="ppomniva-courier__input js-ppomniva-extra-field"
               value="{$ppomniva_saved_fields.door_code|default:''}" />
    </div>
    {/if}

    {if $ppomniva_show_cabinet_no}
    <div class="ppomniva-courier__field">
        <label for="ppomniva-cabinet">{l s='Cabinet number' d='Modules.Ppomniva.Shop' mod='ppomniva'}</label>
        <input type="text" id="ppomniva-cabinet" name="ppomniva_cabinet_number" maxlength="10"
               class="ppomniva-courier__input js-ppomniva-extra-field"
               value="{$ppomniva_saved_fields.cabinet_number|default:''}" />
    </div>
    {/if}

    {if $ppomniva_show_warehouse_no}
    <div class="ppomniva-courier__field">
        <label for="ppomniva-warehouse">{l s='Warehouse number' d='Modules.Ppomniva.Shop' mod='ppomniva'}</label>
        <input type="text" id="ppomniva-warehouse" name="ppomniva_warehouse_number" maxlength="10"
               class="ppomniva-courier__input js-ppomniva-extra-field"
               value="{$ppomniva_saved_fields.warehouse_number|default:''}" />
    </div>
    {/if}

    {if $ppomniva_show_delivery_time && $ppomniva_time_options|count > 0}
    <div class="ppomniva-courier__field">
        <label for="ppomniva-delivery-time">{l s='Preferred delivery time' d='Modules.Ppomniva.Shop' mod='ppomniva'}</label>
        <select id="ppomniva-delivery-time" name="ppomniva_delivery_time"
                class="ppomniva-courier__select js-ppomniva-extra-field">
            {foreach $ppomniva_time_options as $value => $label}
                <option value="{$value}" {if $ppomniva_saved_fields.delivery_time|default:'' == $value}selected{/if}>{$label}</option>
            {/foreach}
        </select>
    </div>
    {/if}

    {if $ppomniva_show_call_before}
    <div class="ppomniva-courier__field ppomniva-courier__field--checkbox">
        <label>
            <input type="checkbox" name="ppomniva_carrier_call" value="1"
                   class="ppomniva-courier__checkbox js-ppomniva-extra-field"
                   {if $ppomniva_saved_fields.carrier_call|default:0}checked{/if} />
            {l s='Call before delivery' d='Modules.Ppomniva.Shop' mod='ppomniva'}
        </label>
    </div>
    {/if}

</div>
