<?php
/**
 * Settings Tab: General
 * 
 * Contains: API Configuration, AI Model Settings, Content Generation, Negative Phrases
 * 
 * @package AEBG
 */
?>
<div class="aebg-settings-grid">
    <!-- API Configuration Section -->
    <div class="aebg-settings-card">
        <div class="aebg-card-header">
            <h2>🔑 API Configuration</h2>
            <div class="aebg-api-status" id="aebg-api-status">
                <span class="aebg-status-indicator" id="aebg-status-indicator"></span>
                <span class="aebg-status-text" id="aebg-status-text">Not tested</span>
            </div>
        </div>
        <div class="aebg-card-content">
            <div class="aebg-form-group">
                <label for="aebg_api_key">OpenAI API Key</label>
                <div class="aebg-input-group">
                    <input type="password" id="aebg_api_key" name="aebg_settings[api_key]" 
                           value="<?php echo esc_attr( isset( $options['api_key'] ) ? $options['api_key'] : '' ); ?>" 
                           class="aebg-input" placeholder="sk-...">
                    <button type="button" class="aebg-toggle-password" data-target="aebg_api_key">
                        <span class="aebg-icon">👁️</span>
                    </button>
                </div>
                <p class="aebg-help-text">
                    <span class="aebg-icon">💡</span>
                    For security, you can also define this in wp-config.php: <code>define( 'AEBG_AI_API_KEY', 'your-key' );</code>
                </p>
            </div>
            <div class="aebg-form-group">
                <label for="aebg_google_api_key">Google (Gemini) API Key <em>(optional, for Nano Banana image generation)</em></label>
                <div class="aebg-input-group">
                    <input type="password" id="aebg_google_api_key" name="aebg_settings[google_api_key]"
                           value="<?php echo esc_attr( isset( $options['google_api_key'] ) ? $options['google_api_key'] : '' ); ?>"
                           class="aebg-input" placeholder="AIza...">
                    <button type="button" class="aebg-toggle-password" data-target="aebg_google_api_key">
                        <span class="aebg-icon">👁️</span>
                    </button>
                </div>
                <p class="aebg-help-text">
                    <span class="aebg-icon">🖼️</span>
                    Required only when using <strong>Nano Banana / Gemini</strong> as the image generation model. Get a key at <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio</a>.
                </p>
            </div>
        </div>
    </div>

    <!-- AI Model Configuration -->
    <div class="aebg-settings-card">
        <div class="aebg-card-header">
            <h2>🤖 AI Model Settings</h2>
        </div>
        <div class="aebg-card-content">
            <div class="aebg-form-group">
                <label for="aebg_model">AI Model</label>
                <select id="aebg_model" name="aebg_settings[model]" class="aebg-select">
                    <optgroup label="GPT-5 Models">
                        <option value="gpt-5.2" <?php selected( isset( $options['model'] ) ? $options['model'] : 'gpt-4o', 'gpt-5.2' ); ?>>GPT-5.2 (Latest & Most Capable)</option>
                        <option value="gpt-5-mini" <?php selected( isset( $options['model'] ) ? $options['model'] : 'gpt-4o', 'gpt-5-mini' ); ?>>GPT-5 Mini (Fast & Efficient)</option>
                    </optgroup>
                    <optgroup label="GPT-4 Models">
                        <option value="gpt-4o" <?php selected( isset( $options['model'] ) ? $options['model'] : 'gpt-4o', 'gpt-4o' ); ?>>GPT-4o (Latest & Most Capable)</option>
                        <option value="gpt-4o-mini" <?php selected( isset( $options['model'] ) ? $options['model'] : 'gpt-4o', 'gpt-4o-mini' ); ?>>GPT-4o Mini (Fast & Efficient)</option>
                        <option value="gpt-4-turbo" <?php selected( isset( $options['model'] ) ? $options['model'] : 'gpt-4o', 'gpt-4-turbo' ); ?>>GPT-4 Turbo (Previous Generation)</option>
                    </optgroup>
                    <optgroup label="GPT-3.5 Models">
                        <option value="gpt-3.5-turbo" <?php selected( isset( $options['model'] ) ? $options['model'] : 'gpt-4o', 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo (Cost Effective)</option>
                    </optgroup>
                </select>
                <p class="aebg-help-text">
                    <span class="aebg-icon">ℹ️</span>
                    Choose the AI model that best fits your needs and budget
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_temperature">Creativity Level (Temperature)</label>
                <div class="aebg-slider-container">
                    <input type="range" id="aebg_temperature" name="aebg_settings[temperature]" 
                           min="0" max="2" step="0.1" 
                           value="<?php echo esc_attr( isset( $options['temperature'] ) ? $options['temperature'] : '0.7' ); ?>" 
                           class="aebg-slider">
                    <div class="aebg-slider-labels">
                        <span>Focused (0.0)</span>
                        <span id="aebg-temperature-value">0.7</span>
                        <span>Creative (2.0)</span>
                    </div>
                </div>
                <p class="aebg-help-text">
                    <span class="aebg-icon">🎯</span>
                    Lower values = more focused, consistent output. Higher values = more creative, varied output
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_max_tokens">Maximum Tokens</label>
                <input type="number" id="aebg_max_tokens" name="aebg_settings[max_tokens]" 
                       value="<?php echo esc_attr( isset( $options['max_tokens'] ) ? $options['max_tokens'] : '1000' ); ?>" 
                       class="aebg-input" min="1" max="4000">
                <p class="aebg-help-text">
                    <span class="aebg-icon">📏</span>
                    Maximum length of generated content (1 token ≈ 4 characters)
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_top_p">Top P (Nucleus Sampling)</label>
                <div class="aebg-slider-container">
                    <input type="range" id="aebg_top_p" name="aebg_settings[top_p]" 
                           min="0" max="1" step="0.05" 
                           value="<?php echo esc_attr( isset( $options['top_p'] ) ? $options['top_p'] : '1.0' ); ?>" 
                           class="aebg-slider">
                    <div class="aebg-slider-labels">
                        <span>Conservative (0.0)</span>
                        <span id="aebg-top-p-value">1.0</span>
                        <span>Diverse (1.0)</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Generation Settings -->
    <div class="aebg-settings-card">
        <div class="aebg-card-header">
            <h2>📝 Content Generation</h2>
        </div>
        <div class="aebg-card-content">
            <div class="aebg-form-group">
                <label for="aebg_default_prompt">Default AI Prompt Template</label>
                <textarea id="aebg_default_prompt" name="aebg_settings[default_prompt]" 
                          class="aebg-textarea" rows="4" 
                          placeholder="Enter your default prompt template..."><?php echo esc_textarea( isset( $options['default_prompt'] ) ? $options['default_prompt'] : 'Generate engaging, SEO-optimized content that is informative and valuable to readers.' ); ?></textarea>
                <p class="aebg-help-text">
                    <span class="aebg-icon">📋</span>
                    This prompt will be used when no specific prompt is provided
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_system_message">System Message (Knowledge Base)</label>
                <textarea id="aebg_system_message" name="aebg_settings[system_message]" 
                          class="aebg-textarea" rows="6" 
                          placeholder="Enter system message to provide knowledge base for AI..."><?php echo esc_textarea( isset( $options['system_message'] ) ? $options['system_message'] : 'You are an expert content creator specializing in e-commerce and product reviews. You have deep knowledge of consumer products, SEO best practices, and content marketing. Always respond in a professional, engaging manner that provides value to readers. Follow these guidelines:

1. Write in the specified language (Danish, Swedish, Norwegian, or English)
2. Use SEO-optimized content structure with proper headings and formatting
3. Include relevant product information when available
4. Maintain consistent tone and style throughout the content
5. Focus on providing valuable insights and helpful information
6. Use natural, conversational language that connects with readers
7. Include specific details and examples when possible
8. Structure content for easy reading with bullet points and paragraphs
9. Always prioritize user value and engagement'); ?></textarea>
                <p class="aebg-help-text">
                    <span class="aebg-icon">🧠</span>
                    This system message provides knowledge and context to enhance all AI content generation. It acts as a knowledge base that the AI uses for every interaction.
                </p>
            </div>

            <div class="aebg-form-group">
                <label class="aebg-checkbox-label aebg-checkbox-enhanced">
                    <input type="checkbox" id="aebg_system_message_enabled" name="aebg_settings[system_message_enabled]" 
                           value="1" <?php checked( isset( $options['system_message_enabled'] ) ? $options['system_message_enabled'] : true ); ?>>
                    <span class="aebg-checkbox-custom"></span>
                    <span class="aebg-checkbox-text">
                        <span class="aebg-icon">✅</span>
                        Enable System Message
                    </span>
                </label>
                <p class="aebg-help-text">
                    <span class="aebg-icon">⚙️</span>
                    When enabled, the system message will be included in all AI interactions to provide additional context and knowledge
                </p>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_content_style">Content Style</label>
                <select id="aebg_content_style" name="aebg_settings[content_style]" class="aebg-select">
                    <option value="professional" <?php selected( isset( $options['content_style'] ) ? $options['content_style'] : 'professional', 'professional' ); ?>>Professional</option>
                    <option value="casual" <?php selected( isset( $options['content_style'] ) ? $options['content_style'] : 'professional', 'casual' ); ?>>Casual & Friendly</option>
                    <option value="technical" <?php selected( isset( $options['content_style'] ) ? $options['content_style'] : 'professional', 'technical' ); ?>>Technical</option>
                    <option value="creative" <?php selected( isset( $options['content_style'] ) ? $options['content_style'] : 'professional', 'creative' ); ?>>Creative & Engaging</option>
                    <option value="academic" <?php selected( isset( $options['content_style'] ) ? $options['content_style'] : 'professional', 'academic' ); ?>>Academic</option>
                </select>
            </div>

            <div class="aebg-form-group">
                <label for="aebg_language">Content Language</label>
                <select id="aebg_language" name="aebg_settings[language]" class="aebg-select">
                    <option value="da" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'da' ); ?>>Danish</option>
                    <option value="sv" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'sv' ); ?>>Swedish</option>
                    <option value="no" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'no' ); ?>>Norwegian</option>
                    <option value="en" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'en' ); ?>>English</option>
                    <option value="es" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'es' ); ?>>Spanish</option>
                    <option value="fr" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'fr' ); ?>>French</option>
                    <option value="de" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'de' ); ?>>German</option>
                    <option value="it" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'it' ); ?>>Italian</option>
                    <option value="pt" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'pt' ); ?>>Portuguese</option>
                    <option value="nl" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'nl' ); ?>>Dutch</option>
                    <option value="ru" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'ru' ); ?>>Russian</option>
                    <option value="ja" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'ja' ); ?>>Japanese</option>
                    <option value="ko" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'ko' ); ?>>Korean</option>
                    <option value="zh" <?php selected( isset( $options['language'] ) ? $options['language'] : 'da', 'zh' ); ?>>Chinese</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Negative Phrases Section -->
    <div class="aebg-settings-card">
        <div class="aebg-card-header">
            <h2>🚫 Negative Phrases</h2>
        </div>
        <div class="aebg-card-content">
            <div class="aebg-form-group">
                <label for="aebg_negative_phrases">Phrases to Avoid</label>
                <p class="aebg-help-text">
                    <span class="aebg-icon">💡</span>
                    Specify phrases that the AI should NEVER use in generated content. The AI will avoid these exact phrases and variations of them.
                </p>
                
                <div class="aebg-negative-phrases-container" id="aebg-negative-phrases-container">
                    <div class="aebg-negative-phrases-input-group">
                        <input type="text" 
                               id="aebg-negative-phrase-input" 
                               class="aebg-input" 
                               placeholder="Enter a phrase to avoid (e.g., 'Jeg elsker bare')">
                        <button type="button" 
                                id="aebg-add-negative-phrase" 
                                class="aebg-btn aebg-btn-primary">
                            <span class="aebg-icon">➕</span>
                            Add Phrase
                        </button>
                    </div>
                    
                    <div class="aebg-negative-phrases-list" id="aebg-negative-phrases-list">
                        <!-- Phrases will be dynamically added here -->
                    </div>
                    
                    <!-- Hidden input to store phrases as JSON array -->
                    <?php 
                    $negative_phrases = isset($options['negative_phrases']) && is_array($options['negative_phrases']) 
                        ? $options['negative_phrases'] 
                        : [];
                    
                    // Ensure all phrases are strings and filter out any invalid entries
                    $negative_phrases = array_filter(array_map(function($phrase) {
                        if (is_string($phrase)) {
                            $trimmed = trim($phrase);
                            // Skip if it looks like a JSON array (corrupted data)
                            if (!empty($trimmed) && !($trimmed[0] === '[' && substr($trimmed, -1) === ']')) {
                                return $trimmed;
                            }
                        }
                        return null;
                    }, $negative_phrases));
                    
                    $negative_phrases_json = json_encode(array_values($negative_phrases), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    ?>
                    <input type="hidden" 
                           id="aebg_negative_phrases" 
                           name="aebg_settings[negative_phrases]" 
                           value="<?php echo esc_attr($negative_phrases_json); ?>">
                </div>
            </div>
        </div>
    </div>
</div>

