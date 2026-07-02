{*
 * 18+ age-restricted flag, injected into the product edit page via
 * hookDisplayAdminProductsExtra. Posted back and persisted by
 * hookActionProductUpdate.
 *}
<div class="ppomniva-product-18plus form-group">
    <label class="control-label">
        <input type="checkbox" name="ppomniva_is_18_plus" value="1" {if $ppomniva_is_18_plus}checked="checked"{/if} />
        {l s='Omniva: 18+ age-restricted product (requires age-verified delivery)' d='Modules.Ppomniva.Admin' mod='ppomniva'}
    </label>
    <input type="hidden" name="ppomniva_id_product" value="{$ppomniva_id_product|intval}" />
</div>
