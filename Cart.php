<?php

class Cart extends CartCore{

    public $carrierPorDeterminar = 0;

    public function getCarrierZone(){
        return Address::getZoneById($this->id_address_delivery);
    }

    public static function getOrderIDByCartId($idcart)
    {
        $result = Db::getInstance()->getRow('SELECT `id_order` FROM '._DB_PREFIX_.'orders WHERE `id_cart` = '.(int)$idcart);
        if (!$result || empty($result) || !array_key_exists('id_order', $result)) {
            return false;
        }

        return $result['id_order'];
    }


    public static function cacheSomeAttributesLists($ipa_list, $id_lang)
    {
        if (!Combination::isFeatureActive()) {
            return;
        }

        $pa_implode = array();

        foreach ($ipa_list as $id_product_attribute) {
            if ((int)$id_product_attribute && !array_key_exists($id_product_attribute.'-'.$id_lang, self::$_attributesLists)) {
                $pa_implode[] = (int)$id_product_attribute;
                self::$_attributesLists[(int)$id_product_attribute.'-'.$id_lang] = array('attributes' => '', 'attributes_small' => '');
            }
        }

        if (!count($pa_implode)) {
            return;
        }

        $result = Db::getInstance()->executeS('
			SELECT pac.`id_product_attribute`, agl.`public_name` AS public_group_name, al.`name` AS attribute_name
			FROM `'._DB_PREFIX_.'product_attribute_combination` pac
			LEFT JOIN `'._DB_PREFIX_.'attribute` a ON a.`id_attribute` = pac.`id_attribute`
			LEFT JOIN `'._DB_PREFIX_.'attribute_group` ag ON ag.`id_attribute_group` = a.`id_attribute_group`
			LEFT JOIN `'._DB_PREFIX_.'attribute_lang` al ON (
				a.`id_attribute` = al.`id_attribute`
				AND al.`id_lang` = '.(int)$id_lang.'
			)
			LEFT JOIN `'._DB_PREFIX_.'attribute_group_lang` agl ON (
				ag.`id_attribute_group` = agl.`id_attribute_group`
				AND agl.`id_lang` = '.(int)$id_lang.'
			)
			WHERE pac.`id_product_attribute` IN ('.implode(',', $pa_implode).')
			ORDER BY ag.`position` ASC, a.`position` ASC'
        );

        foreach ($result as $row) {
            self::$_attributesLists[$row['id_product_attribute'].'-'.$id_lang]['attributes'] .= $row['public_group_name'].' : '.$row['attribute_name'].', ';
            self::$_attributesLists[$row['id_product_attribute'].'-'.$id_lang]['attributes_small'] .= $row['public_group_name'].' : '.$row['attribute_name'].', ';
        }

        foreach ($pa_implode as $id_product_attribute) {
            self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes'] = rtrim(
                self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes'],
                ', '
            );

            self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes_small'] = rtrim(
                self::$_attributesLists[$id_product_attribute.'-'.$id_lang]['attributes_small'],
                ', '
            );
        }
    }




    /**
     * This function returns the total cart amount
     *
     * Possible values for $type:
     * Cart::ONLY_PRODUCTS
     * Cart::ONLY_DISCOUNTS
     * Cart::BOTH
     * Cart::BOTH_WITHOUT_SHIPPING
     * Cart::ONLY_SHIPPING
     * Cart::ONLY_WRAPPING
     * Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING
     * Cart::ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING
     *
     * @param bool $withTaxes With or without taxes
     * @param int $type Total type
     * @param bool $use_cache Allow using cache of the method CartRule::getContextualValue
     * @return float Order total
     */

     /*
    public function getOrderTotal($with_taxes = true, $type = Cart::BOTH, $products = null, $id_carrier = null, $use_cache = true)
    {
        // Dependencies
        $address_factory    = Adapter_ServiceLocator::get('Adapter_AddressFactory');
        $price_calculator    = Adapter_ServiceLocator::get('Adapter_ProductPriceCalculator');
        $configuration        = Adapter_ServiceLocator::get('Core_Business_ConfigurationInterface');

        $ps_tax_address_type = $configuration->get('PS_TAX_ADDRESS_TYPE');
        $ps_use_ecotax = $configuration->get('PS_USE_ECOTAX');
        $ps_round_type = $configuration->get('PS_ROUND_TYPE');
        $ps_ecotax_tax_rules_group_id = $configuration->get('PS_ECOTAX_TAX_RULES_GROUP_ID');
        $compute_precision = $configuration->get('_PS_PRICE_COMPUTE_PRECISION_');


        if (!$this->id) {
            return 0;
        }

        $type = (int)$type;
        $array_type = array(
            Cart::ONLY_PRODUCTS,
            Cart::ONLY_DISCOUNTS,
            Cart::BOTH,
            Cart::BOTH_WITHOUT_SHIPPING,
            Cart::ONLY_SHIPPING,
            Cart::ONLY_WRAPPING,
            Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING,
            Cart::ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING,
        );

        // Define virtual context to prevent case where the cart is not the in the global context
        $virtual_context = Context::getContext()->cloneContext();
        $virtual_context->cart = $this;

        if (!in_array($type, $array_type)) {
            die(Tools::displayError());
        }


        $with_shipping = in_array($type, array(Cart::BOTH, Cart::ONLY_SHIPPING));


        // if cart rules are not used
        if ($type == Cart::ONLY_DISCOUNTS && !CartRule::isFeatureActive()) {
            return 0;
        }

        // no shipping cost if is a cart with only virtuals products
        $virtual = $this->isVirtualCart();
        if ($virtual && $type == Cart::ONLY_SHIPPING) {
            return 0;
        }

        if ($virtual && $type == Cart::BOTH) {
            $type = Cart::BOTH_WITHOUT_SHIPPING;
        }

        if ($with_shipping || $type == Cart::ONLY_DISCOUNTS) {
            if (is_null($products) && is_null($id_carrier)) {
                $shipping_fees = $this->getTotalShippingCost(null, (bool)$with_taxes, null, $products);
            } else {
                $shipping_fees = $this->getPackageShippingCost($id_carrier, (bool)$with_taxes, null, $products);
            }
        } else {
            $shipping_fees = 0;
        }



        if ($type == Cart::ONLY_SHIPPING) {
            return $shipping_fees;
        }

        if ($type == Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING) {
            $type = Cart::ONLY_PRODUCTS;
        }

        $param_product = true;
        if (is_null($products)) {
            $param_product = false;
            $products = $this->getProducts();
        }

        if ($type == Cart::ONLY_PHYSICAL_PRODUCTS_WITHOUT_SHIPPING) {
            foreach ($products as $key => $product) {
                if ($product['is_virtual']) {
                    unset($products[$key]);
                }
            }
            $type = Cart::ONLY_PRODUCTS;
        }


        $order_total = 0;
        if (Tax::excludeTaxeOption()) {
            $with_taxes = false;
        }


        $products_total = array();
        $ecotax_total = 0;

        foreach ($products as $product) {
            // products refer to the cart details

            if ($virtual_context->shop->id != $product['id_shop']) {
                $virtual_context->shop = new Shop((int)$product['id_shop']);
            }

            if ($ps_tax_address_type == 'id_address_invoice') {
                $id_address = (int)$this->id_address_invoice;
            } else {
                $id_address = (int)$product['id_address_delivery'];
            } // Get delivery address of the product from the cart
            if (!$address_factory->addressExists($id_address)) {
                $id_address = null;
            }

            // The $null variable below is not used,
            // but it is necessary to pass it to getProductPrice because
            // it expects a reference.
            $null = null;
            $price = $price_calculator->getProductPrice(
                (int)$product['id_product'],
                $with_taxes,
                (int)$product['id_product_attribute'],
                6,
                null,
                false,
                true,
                $product['cart_quantity'],
                false,
                (int)$this->id_customer ? (int)$this->id_customer : null,
                (int)$this->id,
                $id_address,
                $null,
                $ps_use_ecotax,
                true,
                $virtual_context
            );

            $address = $address_factory->findOrCreate($id_address, true);

            if ($with_taxes) {
                $id_tax_rules_group = Product::getIdTaxRulesGroupByIdProduct((int)$product['id_product'], $virtual_context);
                $tax_calculator = TaxManagerFactory::getManager($address, $id_tax_rules_group)->getTaxCalculator();
            } else {
                $id_tax_rules_group = 0;
            }

            if (in_array($ps_round_type, array(Order::ROUND_ITEM, Order::ROUND_LINE))) {
                if (!isset($products_total[$id_tax_rules_group])) {
                    $products_total[$id_tax_rules_group] = 0;
                }
            } elseif (!isset($products_total[$id_tax_rules_group.'_'.$id_address])) {
                $products_total[$id_tax_rules_group.'_'.$id_address] = 0;
            }

            switch ($ps_round_type) {
                case Order::ROUND_TOTAL:
                    $products_total[$id_tax_rules_group.'_'.$id_address] += $price * (int)$product['cart_quantity'];
                    break;

                case Order::ROUND_LINE:
                    $product_price = $price * $product['cart_quantity'];
                    $products_total[$id_tax_rules_group] += Tools::ps_round($product_price, $compute_precision);
                    break;

                case Order::ROUND_ITEM:
                default:
                    $product_price = $with_taxes ? $tax_calculator->addTaxes($price) : $price;
                    $products_total[$id_tax_rules_group] += Tools::ps_round($product_price, $compute_precision) * (int)$product['cart_quantity'];
                    break;
            }
        }

        foreach ($products_total as $key => $price) {
            $order_total += $price;
        }

        $order_total_products = $order_total;


        if ($type == Cart::ONLY_DISCOUNTS) {
            $order_total = 0;
        }

        // Wrapping Fees
        $wrapping_fees = 0;

        // With PS_ATCP_SHIPWRAP on the gift wrapping cost computation calls getOrderTotal with $type === Cart::ONLY_PRODUCTS, so the flag below prevents an infinite recursion.
        $include_gift_wrapping = (!$configuration->get('PS_ATCP_SHIPWRAP') || $type !== Cart::ONLY_PRODUCTS);

        if ($this->gift && $include_gift_wrapping) {
            $wrapping_fees = Tools::convertPrice(Tools::ps_round($this->getGiftWrappingPrice($with_taxes), $compute_precision), Currency::getCurrencyInstance((int)$this->id_currency));
        }
        if ($type == Cart::ONLY_WRAPPING) {
            return $wrapping_fees;
        }

        $order_total_discount = 0;
        $order_shipping_discount = 0;
        if (!in_array($type, array(Cart::ONLY_SHIPPING, Cart::ONLY_PRODUCTS)) && CartRule::isFeatureActive()) {
            // First, retrieve the cart rules associated to this "getOrderTotal"
            if ($with_shipping || $type == Cart::ONLY_DISCOUNTS) {
                $cart_rules = $this->getCartRules(CartRule::FILTER_ACTION_ALL);
            } else {
                $cart_rules = $this->getCartRules(CartRule::FILTER_ACTION_REDUCTION);
                // Cart Rules array are merged manually in order to avoid doubles
                foreach ($this->getCartRules(CartRule::FILTER_ACTION_GIFT) as $tmp_cart_rule) {
                    $flag = false;
                    foreach ($cart_rules as $cart_rule) {
                        if ($tmp_cart_rule['id_cart_rule'] == $cart_rule['id_cart_rule']) {
                            $flag = true;
                        }
                    }
                    if (!$flag) {
                        $cart_rules[] = $tmp_cart_rule;
                    }
                }
            }

            $id_address_delivery = 0;
            if (isset($products[0])) {
                $id_address_delivery = (is_null($products) ? $this->id_address_delivery : $products[0]['id_address_delivery']);
            }
            $package = array('id_carrier' => $id_carrier, 'id_address' => $id_address_delivery, 'products' => $products);

            // Then, calculate the contextual value for each one
            $flag = false;
            foreach ($cart_rules as $cart_rule) {
                // If the cart rule offers free shipping, add the shipping cost
                if (($with_shipping || $type == Cart::ONLY_DISCOUNTS) && $cart_rule['obj']->free_shipping && !$flag) {
                    $order_shipping_discount = (float)Tools::ps_round($cart_rule['obj']->getContextualValue($with_taxes, $virtual_context, CartRule::FILTER_ACTION_SHIPPING, ($param_product ? $package : null), $use_cache), $compute_precision);
                    $flag = true;
                }

                // If the cart rule is a free gift, then add the free gift value only if the gift is in this package
                if ((int)$cart_rule['obj']->gift_product) {
                    $in_order = false;
                    if (is_null($products)) {
                        $in_order = true;
                    } else {
                        foreach ($products as $product) {
                            if ($cart_rule['obj']->gift_product == $product['id_product'] && $cart_rule['obj']->gift_product_attribute == $product['id_product_attribute']) {
                                $in_order = true;
                            }
                        }
                    }

                    if ($in_order) {
                        $order_total_discount += $cart_rule['obj']->getContextualValue($with_taxes, $virtual_context, CartRule::FILTER_ACTION_GIFT, $package, $use_cache);
                    }
                }

                // If the cart rule offers a reduction, the amount is prorated (with the products in the package)
                if ($cart_rule['obj']->reduction_percent > 0 || $cart_rule['obj']->reduction_amount > 0) {
                    $order_total_discount += Tools::ps_round($cart_rule['obj']->getContextualValue($with_taxes, $virtual_context, CartRule::FILTER_ACTION_REDUCTION, $package, $use_cache), $compute_precision);
                }
            }
            $order_total_discount = min(Tools::ps_round($order_total_discount, 2), (float)$order_total_products) + (float)$order_shipping_discount;
            $order_total -= $order_total_discount;
        }

        if ($type == Cart::BOTH) {
            $order_total += $shipping_fees + $wrapping_fees;
        }

        if ($order_total < 0 && $type != Cart::ONLY_DISCOUNTS) {
            return 0;
        }

        if ($type == Cart::ONLY_DISCOUNTS) {
            return $order_total_discount;
        }

        return Tools::ps_round((float)$order_total, $compute_precision);
    }
    */

    /*
    public function getTotalShippingCost($delivery_option = null, $use_tax = true, Country $default_country = null)
    {
        if (isset(Context::getContext()->cookie->id_country)) {
            $default_country = new Country(Context::getContext()->cookie->id_country);
        }
        if (is_null($delivery_option)) {
            $delivery_option = $this->getDeliveryOption($default_country, false, false);
        }

        $total_shipping = 0;
        $delivery_option_list = $this->getDeliveryOptionList($default_country);
        foreach ($delivery_option as $id_address => $key) {
            if (!isset($delivery_option_list[$id_address]) || !isset($delivery_option_list[$id_address][$key])) {
                continue;
            }
            if ($use_tax) {
                $total_shipping += $delivery_option_list[$id_address][$key]['total_price_with_tax'];
            } else {
                $total_shipping += $delivery_option_list[$id_address][$key]['total_price_without_tax'];
            }
        }

        return $total_shipping;
    }
    */


    /**
     * Return useful informations for cart
     *
     * @return array Cart details
     */
    public function getSummaryDetails($id_lang = null, $refresh = false)
    {
        $context = Context::getContext();
        if (!$id_lang) {
            $id_lang = $context->language->id;
        }

        $delivery = new Address((int)$this->id_address_delivery);
        $invoice = new Address((int)$this->id_address_invoice);

        // New layout system with personalization fields
        $formatted_addresses = array(
            'delivery' => AddressFormat::getFormattedLayoutData($delivery),
            'invoice' => AddressFormat::getFormattedLayoutData($invoice)
        );


        $totalToPay = $this->getOrderTotal(true);
        $totalToPayWithoutTaxes = $this->getOrderTotal(false);
        $iva = $totalToPayWithoutTaxes * 0.21;
        $tax_cost = $iva;

        $base_total_tax_inc = $totalToPayWithoutTaxes + $tax_cost;
        $base_total_tax_exc = $totalToPayWithoutTaxes;

        $total_tax = $base_total_tax_inc - $base_total_tax_exc;

        if ($total_tax < 0) {
            $total_tax = 0;
        }

        $currency = new Currency($this->id_currency);

        $products = $this->getProducts($refresh);

        foreach ($products as $key => &$product) {
            $product['price_without_quantity_discount'] = Product::getPriceStatic(
                $product['id_product'],
                !Product::getTaxCalculationMethod(),
                $product['id_product_attribute'],
                6,
                null,
                false,
                false
            );

            if ($product['reduction_type'] == 'amount') {
                $reduction = (!Product::getTaxCalculationMethod() ? (float)$product['price_wt'] : (float)$product['price']) - (float)$product['price_without_quantity_discount'];
                $product['reduction_formatted'] = Tools::displayPrice($reduction);
            }
        }

        $gift_products = array();
        $cart_rules = $this->getCartRules();
        $total_shipping = $this->getTotalShippingCost();
        $total_shipping_tax_exc = $this->getTotalShippingCost(null, false);
        $total_products_wt = $this->getOrderTotal(true, Cart::ONLY_PRODUCTS);
        $total_products = $this->getOrderTotal(false, Cart::ONLY_PRODUCTS);
        $total_discounts = $this->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        $total_discounts_tax_exc = $this->getOrderTotal(false, Cart::ONLY_DISCOUNTS);

        // The cart content is altered for display
        foreach ($cart_rules as &$cart_rule) {
            // If the cart rule is automatic (wihtout any code) and include free shipping, it should not be displayed as a cart rule but only set the shipping cost to 0
            if ($cart_rule['free_shipping'] && (empty($cart_rule['code']) || preg_match('/^'.CartRule::BO_ORDER_CODE_PREFIX.'[0-9]+/', $cart_rule['code']))) {

                $cart_rule['value_real'] -= $total_shipping;
                $cart_rule['value_tax_exc'] -= $total_shipping_tax_exc;
                $cart_rule['value_real'] = Tools::ps_round($cart_rule['value_real'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
                $cart_rule['value_tax_exc'] = Tools::ps_round($cart_rule['value_tax_exc'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
                if ($total_discounts > $cart_rule['value_real']) {
                    $total_discounts -= $total_shipping;
                }
                if ($total_discounts_tax_exc > $cart_rule['value_tax_exc']) {
                    $total_discounts_tax_exc -= $total_shipping_tax_exc;
                }

                // Update total shipping
                $total_shipping = 0;
                $total_shipping_tax_exc = 0;
            }

            if ($cart_rule['gift_product']) {
                foreach ($products as $key => &$product) {
                    if (empty($product['gift']) && $product['id_product'] == $cart_rule['gift_product'] && $product['id_product_attribute'] == $cart_rule['gift_product_attribute']) {
                        // Update total products
                        $total_products_wt = Tools::ps_round($total_products_wt - $product['price_wt'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
                        $total_products = Tools::ps_round($total_products - $product['price'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);

                        // Update total discounts
                        $total_discounts = Tools::ps_round($total_discounts - $product['price_wt'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
                        $total_discounts_tax_exc = Tools::ps_round($total_discounts_tax_exc - $product['price'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);

                        // Update cart rule value
                        $cart_rule['value_real'] = Tools::ps_round($cart_rule['value_real'] - $product['price_wt'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
                        $cart_rule['value_tax_exc'] = Tools::ps_round($cart_rule['value_tax_exc'] - $product['price'], (int)$context->currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);

                        // Update product quantity
                        $product['total_wt'] = Tools::ps_round($product['total_wt'] - $product['price_wt'], (int)$currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
                        $product['total'] = Tools::ps_round($product['total'] - $product['price'], (int)$currency->decimals * _PS_PRICE_COMPUTE_PRECISION_);
                        $product['cart_quantity']--;

                        if (!$product['cart_quantity']) {
                            unset($products[$key]);
                        }

                        // Add a new product line
                        $gift_product = $product;
                        $gift_product['cart_quantity'] = 1;
                        $gift_product['price'] = 0;
                        $gift_product['price_wt'] = 0;
                        $gift_product['total_wt'] = 0;
                        $gift_product['total'] = 0;
                        $gift_product['gift'] = true;
                        $gift_products[] = $gift_product;

                        break; // One gift product per cart rule
                    }
                }
            }
        }

        foreach ($cart_rules as $key => &$cart_rule) {
            if (((float)$cart_rule['value_real'] == 0 && (int)$cart_rule['free_shipping'] == 0)) {
                unset($cart_rules[$key]);
            }
        }

        $summary = array(
            'delivery' => $delivery,
            'delivery_state' => State::getNameById($delivery->id_state),
            'invoice' => $invoice,
            'invoice_state' => State::getNameById($invoice->id_state),
            'formattedAddresses' => $formatted_addresses,
            'products' => array_values($products),
            'gift_products' => $gift_products,
            'discounts' => array_values($cart_rules),
            'is_virtual_cart' => (int)$this->isVirtualCart(),
            'total_discounts' => $total_discounts,
            'total_discounts_tax_exc' => $total_discounts_tax_exc,
            'total_wrapping' => $this->getOrderTotal(true, Cart::ONLY_WRAPPING),
            'total_wrapping_tax_exc' => $this->getOrderTotal(false, Cart::ONLY_WRAPPING),
            'total_shipping' => $total_shipping,
            'total_shipping_tax_exc' => $total_shipping_tax_exc,
            'total_products_wt' => $total_products_wt,
            'total_products' => $total_products,
            'total_price' => $base_total_tax_inc,
            'total_tax' => $total_tax,
            'total_price_without_tax' => $base_total_tax_exc,
            'is_multi_address_delivery' => $this->isMultiAddressDelivery() || ((int)Tools::getValue('multi-shipping') == 1),
            'free_ship' =>!$total_shipping && !count($this->getDeliveryAddressesWithoutCarriers(true, $errors)),
            'carrier' => new Carrier($this->id_carrier, $id_lang),
        );

        $hook = Hook::exec('actionCartSummary', $summary, null, true);
        if (is_array($hook)) {
            $summary = array_merge($summary, array_shift($hook));
        }

        return $summary;
    }



}
