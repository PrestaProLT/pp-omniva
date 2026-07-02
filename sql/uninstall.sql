-- ppomniva uninstall schema.
-- `PREFIX_` is replaced with the real _DB_PREFIX_ by Uninstaller.
-- NOTE: dropping these tables discards shipment history. The Uninstaller keeps
-- carrier id_reference and order-state config keys so re-install reuses them.

DROP TABLE IF EXISTS `PREFIX_ppomniva_order`;
DROP TABLE IF EXISTS `PREFIX_ppomniva_warehouse`;
DROP TABLE IF EXISTS `PREFIX_ppomniva_manifest`;
DROP TABLE IF EXISTS `PREFIX_ppomniva_terminal`;
DROP TABLE IF EXISTS `PREFIX_ppomniva_log`;
DROP TABLE IF EXISTS `PREFIX_ppomniva_18_plus_product`;
