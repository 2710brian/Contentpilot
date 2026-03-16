<?php

use PHPUnit\Framework\TestCase;

/**
 * ProductManager Unit Tests
 */
class ProductManagerTest extends TestCase {
    
    public function test_normalizeProductData_imageFieldMapping() {
        $product = ['image' => 'http://example.com/image.jpg', 'name' => 'Test Product'];
        $normalized = \AEBG\Core\ProductManager::normalizeProductData($product);
        $this->assertEquals('http://example.com/image.jpg', $normalized['image_url']);
        $this->assertArrayNotHasKey('image', $normalized);
    }
    
    public function test_normalizeProductData_currencyPreservation() {
        $existing = [['currency' => 'DKK', 'name' => 'Existing']];
        $product = ['name' => 'Test'];
        $normalized = \AEBG\Core\ProductManager::normalizeProductData($product, null, $existing);
        $this->assertEquals('DKK', $normalized['currency']);
    }
    
    public function test_preserveProductFieldsOnReplacement() {
        $old = ['currency' => 'DKK', 'network' => 'TestNetwork', 'image_url' => 'http://example.com/old.jpg'];
        $new = ['name' => 'New Product'];
        $preserved = \AEBG\Core\ProductManager::preserveProductFieldsOnReplacement($new, $old);
        $this->assertEquals('DKK', $preserved['currency']);
        $this->assertEquals('TestNetwork', $preserved['network']);
        $this->assertEquals('http://example.com/old.jpg', $preserved['image_url']);
    }
}

