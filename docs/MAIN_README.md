# AI Bulk Generator for Elementor

A state-of-the-art WordPress plugin that automatically generates high-quality blog posts, pages, and custom content using AI-powered content creation with integrated product recommendations.

## 🚀 Features

### Core Functionality
- **AI-Powered Content Generation**: Uses OpenAI GPT models to create engaging, SEO-optimized content
- **Product Integration**: Automatically finds and recommends relevant products using Datafeedr API
- **Elementor Template Support**: Works seamlessly with Elementor templates and widgets
- **Bulk Generation**: Generate multiple posts simultaneously with batch processing
- **Custom Post Types**: Support for posts, pages, and custom post types

### Product Management
- **Product Scout**: Advanced product search and filtering capabilities
- **AI Product Selection**: Intelligent product recommendation using AI analysis
- **Product Image Management**: Automatic download and integration of product images into WordPress media library
- **Product Variables**: Rich set of variables for product integration in content

### Image Generation
- **AI Image Generation**: Create custom images using OpenAI DALL-E models
- **Media Library Integration**: All generated images are properly stored in WordPress media library
- **Image Association**: Images are automatically linked to generated posts

### Advanced Features
- **Background Processing**: Uses WordPress Action Scheduler for reliable background processing
- **Rate Limiting**: Built-in protection against API rate limits
- **Error Handling**: Comprehensive error handling and logging
- **Multi-language Support**: Support for Danish and English content generation

## 📋 Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 7.4 or higher
- **Elementor**: For full template functionality
- **OpenAI API Key**: For AI content and image generation
- **Datafeedr API**: For product search and recommendations

## 🛠️ Installation

### 1. Development Setup
```bash
# Navigate to the plugin directory
cd ai-content-generator-main

# Install dependencies
composer install
```

### 2. Production Deployment
1. Upload the entire `ai-content-generator-main` directory to `/wp-content/plugins/ai-content-generator/`
2. Activate the plugin in WordPress admin
3. Configure API keys in Settings

### 3. Configuration
1. Go to **AI Bulk Generator > Settings**
2. Enter your OpenAI API key
3. Enter your Datafeedr API credentials
4. Test the connections using the test buttons

## 🎯 Usage

### Basic Content Generation
1. Go to **AI Bulk Generator** in WordPress admin
2. Enter your post titles (one per line)
3. Select an Elementor template
4. Choose the number of products to include
5. Configure AI settings (model, creativity, content length)
6. Click "Generate Content"

### Product Scout
1. Go to **AI Bulk Generator > Product Scout**
2. Enter search keywords
3. Apply filters (price range, brands, features)
4. Preview and select products
5. Use selected products in content generation

### Advanced Configuration
- **AI Models**: Choose between GPT-3.5-turbo and GPT-4
- **Creativity Level**: Adjust content creativity (0.1 to 1.0)
- **Content Length**: Set target word count
- **Product Quantity**: Specify number of products per post
- **Image Generation**: Enable/disable AI image generation

## 🔧 Technical Details

### Architecture
- **Plugin.php**: Main plugin initialization and hooks
- **Generator.php**: Core content generation logic
- **ProductImageManager.php**: Image download and management
- **BatchScheduler.php**: Background processing management
- **ActionHandler.php**: Action scheduler integration

### Key Classes
- `AEBG\Plugin`: Main plugin class
- `AEBG\Core\Generator`: Content generation engine
- `AEBG\Core\ProductImageManager`: Image handling
- `AEBG\Core\BatchScheduler`: Background processing
- `AEBG\Admin\Menu`: Admin interface
- `AEBG\Admin\Settings`: Configuration management

### Database Tables
- `wp_aebg_batches`: Batch processing information
- `wp_aebg_batch_items`: Individual generation tasks
- Action Scheduler tables for background processing

## 🎨 Content Variables

### Product Variables
- `{product-1-name}` - Product name
- `{product-1-price}` - Product price
- `{product-1-description}` - Product description
- `{product-1-url}` - Product URL
- `{product-1-featured-image}` - Featured image URL
- `{product-1-featured-image-html}` - Featured image HTML
- `{product-1-gallery-images}` - Gallery image URLs
- `{product-1-gallery-images-html}` - Gallery images HTML

### Content Variables
- `{title}` - Post title
- `{category}` - Content category
- `{target_audience}` - Target audience
- `{content_type}` - Content type
- `{key_topics}` - Key topics covered

### Shortcode Support
```php
[bit_products order="1" field="name"]
[bit_products order="1" field="price"]
[bit_products order="1" field="featured-image-html" size="large"]
```

## 🔍 Troubleshooting

### Common Issues

#### Plugin Not Loading
- Check PHP version (requires 7.4+)
- Verify all files are uploaded correctly
- Check file permissions (644 for files, 755 for directories)
- Run `composer install` if dependencies are missing

#### API Connection Issues
- Verify API keys are correct
- Check API quota and billing
- Test connections in Settings page
- Check WordPress debug log for errors

#### Image Import Issues
- Verify upload directory permissions
- Check available disk space
- Ensure image URLs are accessible
- Check WordPress media library settings

#### Background Processing Issues
- Verify Action Scheduler is working
- Check WordPress cron functionality
- Review error logs for specific issues
- Test with smaller batches

### Debug Mode
Enable debug mode for detailed error messages:

```php
// Add to wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at `/wp-content/debug.log`

## 🔒 Security

- All API keys are stored securely in WordPress options
- Nonce verification for all AJAX requests
- Capability checks for admin functions
- Input sanitization and validation
- Rate limiting to prevent abuse

## 📝 Changelog

### Recent Fixes
- ✅ Fixed private method callback issues
- ✅ Fixed namespace issues with Action Scheduler
- ✅ Fixed double instantiation of admin menus
- ✅ Fixed product quantity selection (user preference now takes priority)
- ✅ Fixed product image import into WordPress media library
- ✅ Fixed AI-generated image import into WordPress media library
- ✅ Enhanced error handling and logging
- ✅ Improved background processing reliability

## 🤝 Support

For support and questions:
1. Check the troubleshooting section above
2. Review WordPress debug logs
3. Test with debug mode enabled
4. Verify all requirements are met

## 📄 License

GPL-2.0-or-later

## 🏗️ Development

### File Structure
```
ai-content-generator-main/
├── ai-bulk-generator-for-elementor.php  # Main plugin file
├── src/                                 # Source code
│   ├── Plugin.php                      # Main plugin class
│   ├── Installer.php                   # Installation logic
│   ├── Admin/                          # Admin interface
│   ├── Core/                           # Core functionality
│   └── API/                            # API integrations
├── assets/                             # CSS, JS, images
├── vendor/                             # Composer dependencies
└── composer.json                       # Dependencies configuration
```

### Contributing
1. Follow WordPress coding standards
2. Test thoroughly before submitting
3. Include proper error handling
4. Document any new features
5. Update this README for significant changes