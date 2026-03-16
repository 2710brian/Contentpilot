# Generator.php Modular Architecture Refactoring Plan

## Executive Summary

This plan outlines a **low-risk, incremental approach** to refactoring the `Generator.php` file (9,267 lines, 116+ methods) into a professional, modular architecture. The strategy prioritizes **backward compatibility**, **zero breaking changes**, and **incremental testing** at each step.

**This plan is fully aligned with your existing architecture patterns:**
- ✅ Follows the same modular pattern as `SchemaEnhancer`, `SchemaValidator`, `SchemaFormatter`
- ✅ Uses static utility classes for pure functions (like your existing utilities)
- ✅ Follows the same dependency injection pattern as `ProductFinder`, `ContentGenerator`, `PostCreator`
- ✅ Aligns with your multi-layer caching strategy (from DATAFEEDR_API_OPTIMIZATION_SOLUTIONS.md)
- ✅ Respects your checkpoint/resume architecture (CheckpointManager pattern)
- ✅ Follows your ActionHandler pattern for background processing

---

## Current State Analysis

### File Statistics
- **Size**: 9,267 lines
- **Methods**: 116+ methods (public and private)
- **Dependencies**: Used by ActionHandler, TemplateManager, CompetitorGeneratorController, BatchScheduler
- **Complexity**: High - handles orchestration, checkpoint management, Elementor processing, validation, cleanup

### Current Architecture
- **Partially Modular**: Already uses separate classes (ProductFinder, ContentGenerator, PostCreator, etc.)
- **Monolithic Core**: Main `run()` method + many private helper methods
- **Tight Coupling**: Elementor processing, variable replacement, validation all in one class

### Key Responsibilities
1. **Workflow Orchestration** (`run()` method - ~700 lines)
2. **Checkpoint Management** (resumeFromCheckpoint, continueFromStepX, execute_step_X methods)
3. **Elementor Template Manipulation** (~40+ private methods)
4. **Product Variable Replacement** (~15+ methods)
5. **Content Regeneration** (~10+ methods)
6. **Data Sanitization/Cleaning** (~10+ methods)
7. **Validation** (~5+ methods)
8. **Cleanup Operations** (~5+ methods)

---

## Refactoring Strategy: Incremental Extraction

### Core Principles
1. **Zero Breaking Changes**: Maintain exact same public API
2. **Incremental Extraction**: Extract one module at a time
3. **Composition Over Inheritance**: Use dependency injection
4. **Test After Each Step**: Verify functionality before proceeding
5. **Backward Compatibility**: Keep old methods as thin wrappers initially
6. **Follow Existing Patterns**: Align with established architecture (SchemaEnhancer, SchemaValidator, ProductFinder patterns)
7. **Static Utility Classes**: Use static methods for pure utility functions (following SchemaEnhancer/SchemaValidator pattern)
8. **Multi-Layer Caching**: Apply caching strategy where applicable (request-level, object cache, database)

---

## Phase 1: Extract Utility/Helper Modules (Lowest Risk)

**Goal**: Extract standalone utility methods that have minimal dependencies.

### Step 1.1: Extract Data Sanitization Module
**Risk Level**: ⭐ Very Low  
**Estimated Time**: 2-3 hours  
**Dependencies**: None (pure functions)

**Methods to Extract**:
- `cleanJsonString()`
- `simplifyElementorData()`
- `sanitizeElementorDataStructure()`
- `sanitizeSettingsArray()`
- `deepCopyArray()`
- `deepCopyElementorData()`
- `decodeJsonWithUnicode()`
- `cleanElementorDataForEncoding()`

**New Class**: `AEBG\Core\ElementorDataSanitizer`

**Architecture Alignment**: Follows the same pattern as `SchemaEnhancer`, `SchemaValidator`, `SchemaFormatter` - static utility class with focused responsibility.

**Implementation**:
```php
namespace AEBG\Core;

/**
 * Elementor Data Sanitizer
 * 
 * Static utility class for sanitizing and cleaning Elementor data structures.
 * Follows the same pattern as SchemaEnhancer/SchemaValidator.
 * 
 * @package AEBG\Core
 */
class ElementorDataSanitizer {
    /**
     * Clean JSON string
     * 
     * @param string $json_string JSON string to clean
     * @return string Cleaned JSON string
     */
    public static function cleanJsonString($json_string) { /* ... */ }
    
    /**
     * Simplify Elementor data structure
     * 
     * @param array $data Elementor data
     * @return array Simplified data
     */
    public static function simplifyElementorData($data) { /* ... */ }
    
    /**
     * Sanitize Elementor data structure recursively
     * 
     * @param array $data Elementor data
     * @return array Sanitized data
     */
    public static function sanitizeElementorDataStructure($data) { /* ... */ }
    
    /**
     * Sanitize settings array recursively
     * 
     * @param array $settings Settings array
     * @param int $depth Current depth (for recursion)
     * @return array Sanitized settings
     */
    public static function sanitizeSettingsArray($settings, $depth = 0) { /* ... */ }
    
    /**
     * Deep copy array (prevents reference issues)
     * 
     * @param array $array Array to copy
     * @return array Deep copy of array
     */
    public static function deepCopyArray($array) { /* ... */ }
    
    /**
     * Deep copy Elementor data
     * 
     * @param array $data Elementor data
     * @return array Deep copy of Elementor data
     */
    public static function deepCopyElementorData($data) { /* ... */ }
    
    /**
     * Decode JSON string with proper Unicode handling
     * 
     * @param string $json_string JSON string
     * @return array|false Decoded data or false on error
     */
    public static function decodeJsonWithUnicode($json_string) { /* ... */ }
    
    /**
     * Clean Elementor data for encoding
     * 
     * @param array $elementor_data Elementor data
     * @param bool $fix_urls Whether to fix URLs
     * @param int $depth Current depth
     * @param float|null $start_time Start time for timeout checks
     * @return array Cleaned data
     */
    public static function cleanElementorDataForEncoding($elementor_data, $fix_urls = true, $depth = 0, $start_time = null) { /* ... */ }
}
```

**Migration Strategy**:
1. Create new class with static methods
2. Copy methods exactly as-is
3. Update Generator.php to use `ElementorDataSanitizer::methodName()` instead of `$this->methodName()`
4. Keep old methods as thin wrappers for 1 release cycle
5. Remove wrappers in next release

**Testing**:
- Run existing tests
- Test Elementor template processing
- Test post creation with various templates

---

### Step 1.2: Extract Validation Module
**Risk Level**: ⭐ Very Low  
**Estimated Time**: 1-2 hours  
**Dependencies**: Minimal

**Methods to Extract**:
- `validateTemplateProductCount()`
- `validateElementorData()`
- `validateResumeData()`
- `getMaxProductNumber()`
- `generateValidationErrorMessage()`

**New Class**: `AEBG\Core\GeneratorValidator`

**Architecture Alignment**: Follows the same pattern as `SchemaValidator` - static validation utility class.

**Implementation**:
```php
namespace AEBG\Core;

/**
 * Generator Validator
 * 
 * Static utility class for validating generator inputs and data structures.
 * Follows the same pattern as SchemaValidator.
 * 
 * @package AEBG\Core
 */
class GeneratorValidator {
    /**
     * Validate template product count matches requirements
     * 
     * @param int $template_id Template ID
     * @param int $selected_product_count Selected product count
     * @return bool|WP_Error True if valid, WP_Error if invalid
     */
    public static function validateTemplateProductCount($template_id, $selected_product_count) { /* ... */ }
    
    /**
     * Validate Elementor data structure
     * 
     * @param array $data Elementor data
     * @return bool True if valid
     */
    public static function validateElementorData($data) { /* ... */ }
    
    /**
     * Validate checkpoint resume data
     * 
     * @param array $checkpoint Checkpoint data
     * @return bool True if valid
     */
    public static function validateResumeData(array $checkpoint): bool { /* ... */ }
    
    /**
     * Get maximum product number from product variables
     * 
     * @param array $product_variables Product variables array
     * @return int Maximum product number
     */
    public static function getMaxProductNumber($product_variables) { /* ... */ }
    
    /**
     * Generate validation error message
     * 
     * @param int $selected_count Selected product count
     * @param int $required_count Required product count
     * @param string $template_title Template title
     * @param array $product_variables Product variables
     * @param array $css_ids CSS IDs
     * @return string Error message
     */
    public static function generateValidationErrorMessage($selected_count, $required_count, $template_title, $product_variables = [], $css_ids = []) { /* ... */ }
}
```

**Migration Strategy**: Same as Step 1.1

---

### Step 1.3: Extract Cleanup Module
**Risk Level**: ⭐ Low  
**Estimated Time**: 2-3 hours  
**Dependencies**: Minimal (WordPress functions)

**Methods to Extract**:
- `cleanupAtJobStart()`
- `cleanupAfterTemplateProcessing()`
- `cleanupAfterElementorProcessing()`
- `cleanupDatabaseConnections()`
- `finalCleanupAfterPostCreation()`
- `resetWordPressHttpTransport()`
- `consumeAllMySQLResults()`
- `resetDatabaseConnection()`

**New Class**: `AEBG\Core\GeneratorCleanup`

**Implementation**:
```php
namespace AEBG\Core;

class GeneratorCleanup {
    public static function cleanupAtJobStart() { /* ... */ }
    public static function cleanupAfterTemplateProcessing() { /* ... */ }
    public static function cleanupAfterElementorProcessing() { /* ... */ }
    public static function cleanupDatabaseConnections() { /* ... */ }
    public static function finalCleanupAfterPostCreation($post_id) { /* ... */ }
    public static function resetWordPressHttpTransport() { /* ... */ }
    public static function consumeAllMySQLResults() { /* ... */ }
    public static function resetDatabaseConnection() { /* ... */ }
}
```

**Migration Strategy**: Same as Step 1.1

---

## Phase 2: Extract Workflow Steps (Medium Risk)

**Goal**: Extract individual workflow steps into dedicated classes.

### Step 2.1: Extract Workflow Orchestrator
**Risk Level**: ⭐⭐ Medium  
**Estimated Time**: 4-6 hours  
**Dependencies**: All step handlers

**New Class**: `AEBG\Core\GenerationWorkflow`

**Purpose**: Orchestrates the main workflow steps, delegating to step handlers.

**Implementation**:
```php
namespace AEBG\Core;

class GenerationWorkflow {
    private $generator; // Reference to Generator for access to dependencies
    
    public function __construct(Generator $generator) {
        $this->generator = $generator;
    }
    
    public function execute(
        string $title,
        int $template_id,
        array $settings,
        ?int $item_id = null,
        ?array $competitor_products = null
    ) {
        // Extract the main workflow logic from Generator::run()
        // Delegate to step handlers
    }
}
```

**Migration Strategy**:
1. Create `GenerationWorkflow` class
2. Move workflow orchestration logic from `Generator::run()` to `GenerationWorkflow::execute()`
3. Keep `Generator::run()` as a thin wrapper that instantiates and calls `GenerationWorkflow`
4. Test thoroughly before removing wrapper

---

### Step 2.2: Extract Step Handlers
**Risk Level**: ⭐⭐ Medium  
**Estimated Time**: 6-8 hours per step  
**Dependencies**: Generator dependencies (variables, context_registry, etc.)

**Steps to Extract**:
1. **Step 1 Handler**: `AEBG\Core\WorkflowSteps\TitleAnalysisStep`
2. **Step 2 Handler**: `AEBG\Core\WorkflowSteps\ProductDiscoveryStep`
3. **Step 3 Handler**: `AEBG\Core\WorkflowSteps\ProductSelectionStep`
4. **Step 4 Handler**: `AEBG\Core\WorkflowSteps\TemplateProcessingStep`
5. **Step 5 Handler**: `AEBG\Core\WorkflowSteps\ContentGenerationStep`
6. **Step 6 Handler**: `AEBG\Core\WorkflowSteps\PostCreationStep`

**Example Implementation**:
```php
namespace AEBG\Core\WorkflowSteps;

class TitleAnalysisStep {
    private $variables;
    private $settings;
    private $observer;
    
    public function __construct($variables, $settings, $observer) {
        $this->variables = $variables;
        $this->settings = $settings;
        $this->observer = $observer;
    }
    
    public function execute(string $title, string $api_key, string $ai_model) {
        // Extract Step 1 logic from Generator::run()
    }
}
```

**Migration Strategy**:
1. Extract one step at a time
2. Test each step in isolation
3. Update `GenerationWorkflow` to use step handlers
4. Keep old `execute_step_X` methods as wrappers initially

---

## Phase 3: Extract Elementor Processing (Higher Risk)

**Goal**: Extract all Elementor-related methods into dedicated classes.

### Step 3.1: Extract Elementor Template Manipulator
**Risk Level**: ⭐⭐⭐ Medium-High  
**Estimated Time**: 8-10 hours  
**Dependencies**: ElementorTemplateProcessor, ContextRegistry

**Methods to Extract** (~40+ methods):
- `findProductContainer()`
- `findTestvinderContainer()`
- `updateProductVariablesInContainer()`
- `updateProductVariablesInElementorData()`
- `replaceContainerInElementorData()`
- `createNewProductContainer()`
- `updateTemplateForNewProduct()`
- `updateTemplateAfterRemoval()`
- `regenerateProductContainerContent()`
- `processContainerForReplacement()`
- `applyContentToContainer()`
- `processAIContentInContainer()`
- And ~30 more related methods...

**New Class**: `AEBG\Core\ElementorTemplateManipulator`

**Implementation**:
```php
namespace AEBG\Core;

class ElementorTemplateManipulator {
    private $context_registry;
    private $ai_processor;
    private $variable_replacer;
    
    public function __construct($context_registry, $ai_processor, $variable_replacer) {
        $this->context_registry = $context_registry;
        $this->ai_processor = $ai_processor;
        $this->variable_replacer = $variable_replacer;
    }
    
    // Move all Elementor manipulation methods here
}
```

**Migration Strategy**:
1. Create class with all methods
2. Update Generator to instantiate and delegate
3. Keep old methods as wrappers
4. Test extensively with various templates
5. Remove wrappers after verification

---

### Step 3.2: Extract Product Variable Processor
**Risk Level**: ⭐⭐ Medium  
**Estimated Time**: 4-6 hours  
**Dependencies**: VariableReplacer

**Methods to Extract**:
- `findProductVariablesInTemplate()`
- `extractProductVariablesFromText()`
- `updateProductVariablesInContainer()`
- `updateProductVariablesInSettings()`
- `updateProductVariablesInContainerRecursively()`
- `updateProductVariablesInContainerAndChildren()`
- `updateProductVariablesInContainerLegacy()`
- `fixAIPromptProductReferences()`

**New Class**: `AEBG\Core\ProductVariableProcessor`

**Migration Strategy**: Same as Step 3.1

---

## Phase 4: Extract Checkpoint Management (Complex)

**Goal**: Extract checkpoint/resume functionality.

### Step 4.1: Extract Checkpoint Resumer
**Risk Level**: ⭐⭐⭐ Medium-High  
**Estimated Time**: 6-8 hours  
**Dependencies**: CheckpointManager, all step handlers

**Methods to Extract**:
- `resumeFromCheckpoint()`
- `continueFromStep1()`
- `continueFromStep2()`
- `continueFromStep3()`
- `continueFromStep3_6()`
- `continueFromStep3_7()`
- `continueFromStep4()`
- `continueFromStep5()`
- `continueFromStep5_5()`

**New Class**: `AEBG\Core\CheckpointResumer`

**Implementation**:
```php
namespace AEBG\Core;

class CheckpointResumer {
    private $generator; // For access to step handlers
    
    public function __construct(Generator $generator) {
        $this->generator = $generator;
    }
    
    public function resume(array $checkpoint, int $item_id) {
        // Extract resume logic
    }
}
```

**Migration Strategy**:
1. Extract resume logic carefully
2. Test with various checkpoint states
3. Ensure backward compatibility
4. Test resume scenarios extensively

---

## Phase 5: Refactor Main Generator Class (Final Step)

**Goal**: Transform Generator into a thin orchestrator.

### Step 5.1: Simplify Generator Class
**Risk Level**: ⭐⭐⭐ Medium  
**Estimated Time**: 4-6 hours  

**Final Structure**:
```php
class Generator {
    // Dependencies (injected)
    private $workflow;
    private $checkpoint_resumer;
    private $cleanup;
    private $validator;
    
    // Core components (existing)
    private $variables;
    private $context_registry;
    private $product_finder;
    private $content_generator;
    private $post_creator;
    // ... etc
    
    public function __construct($settings, $job_start_time = null, $author_id = null) {
        // Initialize core components
        // Initialize workflow orchestrator
    }
    
    public function run($title, $template_id, $settings = [], $item_id = null, $competitor_products = null) {
        // Thin wrapper that delegates to workflow
        return $this->workflow->execute($title, $template_id, $settings, $item_id, $competitor_products);
    }
    
    // Keep public API methods for backward compatibility
    public function regenerateProductContent($post_id, $product_number, $product) { /* ... */ }
    public function updateTemplateForNewProduct($post_id, $new_product_number, $products = null) { /* ... */ }
    // ... etc
}
```

---

## Implementation Timeline

### Week 1: Phase 1 (Utility Modules)
- **Day 1-2**: Extract Data Sanitization Module
- **Day 3**: Extract Validation Module
- **Day 4-5**: Extract Cleanup Module
- **Day 6-7**: Testing & bug fixes

### Week 2: Phase 2 (Workflow Steps)
- **Day 1-2**: Extract Workflow Orchestrator
- **Day 3-4**: Extract Step 1-3 Handlers
- **Day 5-6**: Extract Step 4-6 Handlers
- **Day 7**: Testing & integration

### Week 3: Phase 3 (Elementor Processing)
- **Day 1-3**: Extract Elementor Template Manipulator
- **Day 4-5**: Extract Product Variable Processor
- **Day 6-7**: Testing & bug fixes

### Week 4: Phase 4-5 (Checkpoint & Final Refactor)
- **Day 1-3**: Extract Checkpoint Resumer
- **Day 4-5**: Refactor Main Generator Class
- **Day 6-7**: Final testing & documentation

---

## Risk Mitigation Strategies

### 1. Backward Compatibility
- **Strategy**: Keep all public methods as thin wrappers initially
- **Timeline**: Remove wrappers after 1-2 release cycles
- **Testing**: Ensure all existing code continues to work

### 2. Incremental Testing
- **After Each Step**: Run full test suite
- **Integration Tests**: Test with real templates and products
- **Regression Tests**: Verify no functionality is broken

### 3. Code Review Process
- **Review Each Module**: Before merging
- **Pair Programming**: For complex extractions
- **Documentation**: Update with each extraction

### 4. Rollback Plan
- **Git Branches**: One branch per phase
- **Feature Flags**: Optional - use new modules behind flags
- **Quick Revert**: Keep old code until fully verified

---

## Success Criteria

### Phase 1 Complete When:
- ✅ All utility modules extracted
- ✅ Generator.php reduced by ~500 lines
- ✅ All tests passing
- ✅ No functionality regressions

### Phase 2 Complete When:
- ✅ Workflow orchestrator extracted
- ✅ All step handlers extracted
- ✅ Generator.php reduced by ~1,500 lines
- ✅ All tests passing
- ✅ Checkpoint/resume still works

### Phase 3 Complete When:
- ✅ Elementor processing extracted
- ✅ Generator.php reduced by ~2,000 lines
- ✅ All tests passing
- ✅ Template processing works correctly

### Phase 4 Complete When:
- ✅ Checkpoint management extracted
- ✅ Generator.php reduced by ~1,000 lines
- ✅ All tests passing
- ✅ Resume functionality works correctly

### Final Goal:
- ✅ Generator.php < 1,500 lines (from 9,267)
- ✅ Clear separation of concerns
- ✅ All functionality preserved
- ✅ Improved testability
- ✅ Better maintainability

---

## Recommended Starting Point

**Start with Phase 1, Step 1.1: Extract Data Sanitization Module**

**Why This First?**
1. **Lowest Risk**: Pure utility functions with no dependencies
2. **Quick Win**: Immediate reduction in file size (~300 lines)
3. **Easy Testing**: Simple to verify correctness
4. **Builds Confidence**: Establishes pattern for future extractions
5. **No Breaking Changes**: Completely safe

**Next Steps After Step 1.1**:
1. Test thoroughly
2. Commit and tag
3. Proceed to Step 1.2 (Validation Module)
4. Continue with remaining Phase 1 steps

---

## Architecture Alignment

### Existing Patterns to Follow

1. **Static Utility Classes** (like SchemaEnhancer, SchemaValidator, SchemaFormatter)
   - Use static methods for pure utility functions
   - No state, no dependencies
   - Easy to test and maintain

2. **Modular Components** (like ProductFinder, ContentGenerator, PostCreator)
   - Single responsibility
   - Dependency injection via constructor
   - Clear interfaces

3. **Multi-Layer Caching** (from DATAFEEDR_API_OPTIMIZATION_SOLUTIONS.md)
   - Request-level static cache (fastest)
   - WordPress object cache (cross-request)
   - Database cache (persistent)
   - Transient cache (long-term)
   - API call (last resort)

4. **Checkpoint/Resume Pattern** (existing in Generator)
   - CheckpointManager for state management
   - Step-based execution (execute_step_X methods)
   - Resume from any step

5. **ActionHandler Pattern** (for background processing)
   - Action Scheduler integration
   - Progress tracking
   - Error handling

### Naming Conventions

- **Utility Classes**: `*Sanitizer`, `*Validator`, `*Formatter`, `*Enhancer`
- **Manager Classes**: `*Manager` (e.g., `CheckpointManager`, `ComparisonManager`)
- **Processor Classes**: `*Processor` (e.g., `ElementorTemplateProcessor`, `AIPromptProcessor`)
- **Helper Classes**: `*Helper` (e.g., `TestvinderHelper`)

### File Organization

```
src/Core/
├── ElementorDataSanitizer.php      (NEW - static utility)
├── GeneratorValidator.php          (NEW - static utility)
├── GeneratorCleanup.php             (NEW - static utility)
├── GenerationWorkflow.php          (NEW - orchestrator)
├── WorkflowSteps/                  (NEW - directory)
│   ├── TitleAnalysisStep.php
│   ├── ProductDiscoveryStep.php
│   ├── ProductSelectionStep.php
│   ├── TemplateProcessingStep.php
│   ├── ContentGenerationStep.php
│   └── PostCreationStep.php
├── ElementorTemplateManipulator.php (NEW - processor)
├── ProductVariableProcessor.php     (NEW - processor)
└── CheckpointResumer.php            (NEW - manager)
```

## Notes

- **Dependency Injection**: Follow existing pattern (constructor injection, no DI container needed)
- **Interfaces**: Consider creating interfaces for step handlers for better testability (optional)
- **Documentation**: Update inline docs and README with each extraction
- **Performance**: Monitor performance after each phase (should be neutral or better)
- **Caching**: Apply multi-layer caching pattern where applicable (request-level, object cache, database)
- **Logging**: Use existing Logger class for consistent logging

---

## Questions to Consider

1. Should we use a DI container (e.g., PHP-DI)?
2. Should step handlers implement a common interface?
3. Should we add unit tests for extracted modules?
4. Should we create a migration guide for developers?

---

## Alignment with Existing Architecture

### ✅ Verified Alignment

This plan has been reviewed against your existing refactoring and optimization documents:

1. **Schema Implementation Pattern** (SCHEMA_IMPLEMENTATION_COMPLETE.md)
   - ✅ Uses same modular component structure (Enhancer, Validator, Formatter)
   - ✅ Static utility classes for pure functions
   - ✅ Clear separation of concerns

2. **Datafeedr Optimization Pattern** (DATAFEEDR_API_OPTIMIZATION_SOLUTIONS.md)
   - ✅ Multi-layer caching strategy applicable where needed
   - ✅ Request-level static cache pattern
   - ✅ Database-first lookup approach

3. **Elementor Modals Pattern** (ELEMENTOR_MODALS_IMPLEMENTATION_PLAN.md)
   - ✅ Component-based architecture
   - ✅ Reuse existing patterns and utilities
   - ✅ Minimal custom code

4. **Reorder Conflict Pattern** (REORDER_CONFLICT_RESOLUTION_SPEC.md)
   - ✅ Separation of Concerns
   - ✅ Dependency Injection
   - ✅ Fail-Safe Defaults
   - ✅ Security First
   - ✅ Performance Optimized
   - ✅ Observable (logging)

5. **Featured Image Pattern** (FEATURED_IMAGE_REGENERATION_PLAN.md)
   - ✅ Modular, component-based architecture
   - ✅ Reuse existing UI components
   - ✅ Clear module boundaries

6. **Networks Tab Pattern** (NETWORKS_TAB_REFACTOR_PLAN.md)
   - ✅ Class-based JavaScript architecture
   - ✅ Component structure
   - ✅ Separation of concerns

### Architecture Consistency

All extracted modules will follow these established patterns:

- **Static Utility Classes**: `*Sanitizer`, `*Validator`, `*Formatter` (like SchemaEnhancer)
- **Manager Classes**: `*Manager` (like CheckpointManager, ComparisonManager)
- **Processor Classes**: `*Processor` (like ElementorTemplateProcessor, AIPromptProcessor)
- **Dependency Injection**: Constructor injection (like ProductFinder, ContentGenerator)
- **Error Handling**: WP_Error for errors, graceful degradation
- **Logging**: Use existing Logger class consistently

---

**Document Version**: 1.1  
**Last Updated**: 2024  
**Author**: AI Assistant  
**Status**: Draft - Aligned with Existing Architecture - Ready for Review

