<?php

use PHPUnit\Framework\TestCase;

/**
 * ProductTransactionManager Unit Tests
 */
class ProductTransactionManagerTest extends TestCase {
    
    public function test_executeWithRollback_success() {
        // Mock successful operation
        $operation = function($post_id, $products) {
            return true;
        };
        
        $result = \AEBG\Core\ProductTransactionManager::executeWithRollback(1, [], $operation);
        $this->assertTrue($result['success']);
    }
    
    public function test_executeWithRollback_rollbackOnFailure() {
        // Mock failing operation
        $operation = function($post_id, $products) {
            throw new \Exception('Test failure');
        };
        
        $result = \AEBG\Core\ProductTransactionManager::executeWithRollback(1, [], $operation);
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }
}

