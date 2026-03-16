# AI Bulk Generator for WordPress

A powerful WordPress plugin for bulk content generation using AI, with advanced affiliate network management and product comparison features.

## 🚀 Features

### Core Functionality
- **AI Content Generation**: Bulk generate high-quality content using AI
- **Product Discovery**: Scout and discover products from affiliate networks
- **Network Management**: Comprehensive affiliate network configuration
- **Price Comparison**: Dynamic product price comparison tables
- **Elementor Integration**: Seamless integration with Elementor page builder

### Network Management
- **Simplified Interface**: Clean, user-friendly network configuration
- **Database Storage**: Efficient storage of API networks and affiliate IDs
- **Real-time Search**: Quick network discovery and filtering
- **Multi-user Support**: Separate configurations for different users

### Technical Features
- **Datafeedr Integration**: Direct API integration for network data
- **Performance Optimized**: Database-driven architecture for speed
- **Responsive Design**: Mobile-friendly admin interface
- **WordPress Standards**: Follows WordPress coding standards

## 📁 Project Structure

```
ai-content-generator-main/
├── src/                    # Core plugin source code
│   ├── Admin/             # Admin interface and views
│   ├── Core/              # Core functionality
│   ├── API/               # API integrations
│   └── Plugin.php         # Main plugin class
├── assets/                 # CSS, JavaScript, and other assets
├── docs/                   # Documentation
│   ├── MAIN_README.md     # This file
│   └── NETWORKS_PAGE_README.md  # Networks page documentation
├── vendor/                 # Composer dependencies
└── composer.json          # PHP dependencies
```

## 🛠️ Installation

1. Upload the plugin to your WordPress site
2. Activate the plugin through the WordPress admin
3. Configure your Datafeedr API credentials
4. Set up your affiliate networks
5. Start generating content!

## 🔧 Configuration

### Datafeedr API Setup
1. Navigate to **AI Bulk Generator > Settings**
2. Enter your Datafeedr API credentials
3. Save settings

### Network Configuration
1. Go to **AI Bulk Generator > Networks**
2. Enter affiliate IDs for your networks
3. Save configurations

## 📚 Documentation

- **[Main Documentation](docs/MAIN_README.md)**: Complete plugin overview
- **[Networks Page](docs/NETWORKS_PAGE_README.md)**: Network management guide

## 🎯 Usage

### Content Generation
```php
[aebg_generator template="product_review" count="10"]
```

### Price Comparison
```php
[aebg_price_comparison product="123" style="table" limit="8"]
```

### Product Scout
```php
[aebg_product_scout category="electronics" limit="20"]
```

## 🔌 Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.7+
- Datafeedr API account

## 🚀 Development

### Setup Development Environment
```bash
composer install
```

### Database Tables
The plugin automatically creates necessary database tables:
- `wp_aebg_networks` - Network information storage
- `wp_aebg_affiliate_ids` - User affiliate ID configurations
- `wp_aebg_batches` - Content generation batches
- `wp_aebg_batch_items` - Individual batch items

### Code Standards
- Follows WordPress coding standards
- PSR-4 autoloading
- Proper namespacing
- Comprehensive error handling

## 📝 Changelog

### Version 2.0.0
- Complete UI redesign for better user experience
- Database-driven network management
- Improved performance and reliability
- Simplified network configuration interface

### Version 1.0.0
- Initial release
- Basic AI content generation
- Network management features

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 🆘 Support

For support and questions:
- Check the documentation in the `docs/` folder
- Review the code comments
- Open an issue on GitHub

---

**AI Bulk Generator** - Making content creation simple and efficient. 