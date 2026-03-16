# Filoversigt (Index) – AI Bulk Generator for Elementor

Oversigt over projektets filer, grupperet efter mappe og formål.

---

## Rodfiler (plugin-root)

| Fil | Beskrivelse |
|-----|-------------|
| `ai-bulk-generator-for-elementor.php` | Hoved-pluginfil, indlæses af WordPress |
| `composer.json` / `composer.lock` | PHP-afhængigheder |
| `activate-migration-tool.php` | Aktiverer migrationsværktøj |
| `database-migration.php` | Database-migration |
| `run-migration.php` | Kør migration |
| `simplify-database-structure.php` | Script til at forenkle databasestruktur |
| `phpunit.xml` | PHPUnit-konfiguration |
| `README.md` | Projektbeskrivelse |
| `.gitignore` | Git-ignorerede filer |

---

## `src/` – PHP-kildekode

### Kerne
| Fil | Beskrivelse |
|-----|-------------|
| `Plugin.php` | Plugin-bootstrap og registrering |
| `Installer.php` | Installation/opgradering (tabeller, options) |

### `src/API/` – REST/Admin-AJAX controllere
| Fil | Beskrivelse |
|-----|-------------|
| `BatchStatusController.php` | Batch-status |
| `CompetitorTrackingController.php` | Konkurrent-tracking |
| `CompetitorGeneratorController.php` | Konkurrent-generator |
| `GeneratorV2Controller.php` | Generator v2 |
| `PromptTemplateController.php` | Prompt-skabeloner |
| `FeaturedImageController.php` | Fremhævet billede |
| `DashboardController.php` | Dashboard-data |
| `NetworkAnalyticsController.php` | Netværksanalyser |
| `LogsController.php` | Logs |

### `src/Admin/` – Admin-menu, indstillinger, UI
| Fil | Beskrivelse |
|-----|-------------|
| `Menu.php` | Admin-menu og undersider |
| `Settings.php` | Indstillinger (generelt, tabs) |
| `Meta_Box.php` | Meta-bokse på redigeringsskærme |
| `FeaturedImageUI.php` | UI til fremhævede billeder |
| `BulkFeaturedImageFinder.php` | Bulk-søgning af fremhævede billeder |
| `BulkCategoryManager.php` | Bulk-kategorier |
| `Networks_Manager.php` | Netværkshåndtering |
| `Networks_Data.php` | Netværksdata |

### `src/Admin/views/` – Admin-sider og modaler
| Fil | Beskrivelse |
|-----|-------------|
| `dashboard-page.php` | Dashboard |
| `generator-page.php` | Generator (v1) |
| `generator-v2-page.php` | Generator v2 |
| `logs-page.php` | Log-side |
| `product-scout-page.php` | Product Scout |
| `competitor-tracking-page.php` | Konkurrent-tracking |
| `network-analytics-page.php` | Netværksanalyser |
| `networks-page.php` | Netværker |
| `prompt-templates-page.php` | Prompt-skabeloner |
| `clone-content-page.php` | Klon indhold |
| `results-page.php` | Resultater |
| `trustpilot-scraper-page.php` | Trustpilot-scraper |
| `settings-page.php` | Indstillinger (container) |
| `settings-tab-general.php` | Fanen Generelt |
| `settings-tab-advanced.php` | Fanen Avanceret |
| `settings-tab-networks.php` | Fanen Netværk |
| `settings-tab-merchants.php` | Fanen Forhandlere |
| `settings-tab-logs.php` | Fanen Logs |
| `settings-tab-integrations.php` | Fanen Integrationer |
| `settings-tab-competitor-tracking.php` | Fanen Konkurrent-tracking |
| `featured-image-regenerate-modal.php` | Modal: regenerer fremhævet billede |
| `merchant-comparison-modal.php` | Modal: forhandler-sammenligning |
| `reorder-conflict-modal.php` | Modal: rekkefølge-konflikt |
| `modern-network-selector.php` | Komponent: netværksvælger |

### `src/Core/` – Forretningslogik og hjælpere
| Fil | Beskrivelse |
|-----|-------------|
| `ContentGenerator.php` | Indholdsgenerering |
| `Generator.php` | Generator-kerne |
| `AIPromptProcessor.php` | AI-promptbehandling |
| `ContentFormatter.php` | Formatering af indhold |
| `ActionHandler.php` | Aktionshåndtering |
| `ActionSchedulerHelper.php` | Action Scheduler-integration |
| `BatchScheduler.php` | Batch-planlægning |
| `CheckpointManager.php` | Checkpoints under kørsel |
| `Datafeedr.php` | Datafeedr API |
| `DataUtilities.php` | Data-hjælpefunktioner |
| `APIClient.php` | Generel API-klient |
| `CurrencyManager.php` | Valuta |
| `DuplicateDetector.php` | Duplikatdetektion |
| `ErrorHandler.php` | Fejlhåndtering |
| `Logger.php` | Logging |
| `JobTracker.php` | Job-tracking |
| `ContextRegistry.php` | Kontekst-register |
| `ElementorTemplateProcessor.php` | Elementor-skabelonbehandling |
| `ElementorDataCleaner.php` | Rydning af Elementor-data |
| `FeaturedImageGenerator.php` | Generering af fremhævet billede |
| `ImageProcessor.php` | Billedbehandling |
| `CategoryGenerator.php` | Kategorigenerering |
| `ComparisonManager.php` | Sammenligning (f.eks. forhandlere) |
| `MerchantComparisonHandler.php` | Håndtering af forhandler-sammenligning |
| `CompetitorTrackingManager.php` | Konkurrent-tracking |
| `CompetitorScraper.php` | Konkurrent-scraping |
| `CompetitorAnalyzer.php` | Konkurrentanalyse |
| `CompetitorProductFetcher.php` | Hent konkurrentprodukter |
| `CompetitorProductConverter.php` | Konverter konkurrentprodukter |
| `GenerationObserver.php` | Observer under generering |

### `src/EmailMarketing/` – E-mailmarkedsføring
- **Admin:** `Admin/EmailMarketingMenu.php`, `Admin/views/*` (campaigns, lists, templates, subscribers, analytics, settings-faner)
- **API:** `API/EmailSignupController.php`, `API/WebhookController.php`
- **Core:** `Core/CampaignManager.php`, `Core/ListManager.php`, `Core/QueueManager.php`, `Core/SubscriberManager.php`, `Core/EventListener.php`, `Core/TrackingEndpoints.php`
- **Repositories:** `Repositories/CampaignRepository.php`, `ListRepository.php`, `QueueRepository.php`, `SubscriberRepository.php`, `TemplateRepository.php`, `TrackingRepository.php`
- **Services:** `Services/AnalyticsService.php`, `EmailService.php`, `TemplateService.php`, `ValidationService.php`
- **Utils:** `Utils/ClickTracker.php`, `EmailValidator.php`, `OpenTracker.php`, `OptInManager.php`, `TemplateProcessor.php`, `TokenGenerator.php`, `UnsubscribeManager.php`

### `src/Elementor/DynamicTags/` – Elementor dynamiske tags
| Fil | Beskrivelse |
|-----|-------------|
| `ProductName.php`, `ProductPrice.php`, `ProductImage.php`, `ProductUrl.php`, `ProductAffiliateUrl.php` | Produkt-tags |
| `TestvinderName.php`, `TestvinderPrice.php`, `TestvinderImage.php`, `TestvinderUrl.php`, `TestvinderRating.php`, `TestvinderAffiliateUrl.php` | Testvinder-tags |

---

## `assets/` – Frontend-ressourcer

### JavaScript (`assets/js/`)
| Fil | Beskrivelse |
|-----|-------------|
| `admin.js` | Generel admin |
| `dashboard.js` | Dashboard |
| `generator.js` | Generator v1 |
| `generator-v2.js` | Generator v2 |
| `logs-page.js` | Log-side |
| `product-scout.js` | Product Scout |
| `competitor-tracking.js` | Konkurrent-tracking |
| `networks-tab.js` | Netværk-faner |
| `modern-networks-selector.js` / `modern-network-selector.js` | Netværksvælger |
| `prompt-templates-admin.js` | Prompt-skabeloner admin |
| `elementor-prompt-templates.js` | Elementor prompt-skabeloner |
| `settings-tabs.js` / `tabs.js` | Faner |
| `clone-content.js` | Klon indhold |
| `edit-posts.js` | Rediger indlæg |
| `featured-image-regenerate.js` | Regenerer fremhævet billede |
| `bulk-categories.js` | Bulk-kategorier |
| `email-marketing-admin.js` | E-mailmarkedsføring admin |
| `email-signup-widget.js` | E-mail-tilmelding widget |
| `frontend-comparison.js` | Frontend-sammenligning |
| `reorder-progress-ui.js` | Rekkefølge-fremdrift |
| `reorder-conflict-handler.js` | Rekkefølge-konflikter |

### CSS (`assets/css/`)
| Fil | Beskrivelse |
|-----|-------------|
| `admin.css`, `dashboard.css`, `generator.css`, `generator-v2.css` | Admin og generator |
| `logs-page.css`, `edit-posts.css`, `tabs.css`, `settings-tabs.css` | Sider og faner |
| `networks-tab.css`, `modern-networks-selector.css` | Netværk |
| `prompt-templates.css`, `product-list-widget.css` | Skabeloner og widgets |
| `competitor-tracking.css`, `frontend-comparison.css` | Konkurrent / sammenligning |
| `featured-image-regenerate.css`, `bulk-featured-image.css`, `bulk-categories.css` | Billeder og kategorier |
| `email-marketing-admin.css`, `email-signup-widget.css` | E-mailmarkedsføring |

---

## `docs/` – Dokumentation

| Fil | Beskrivelse |
|-----|-------------|
| `INDEX.md` | Denne filoversigt |
| `MAIN_README.md` | Hovedoversigt og guide |
| `VARIABLES_REFERENCE.md` / `VARIABLES_COMPARISON.md` | Variabler |
| `FEATURED_IMAGE_*.md` | Fremhævet billede (plan, implementering, konflikt, regeneration, Action Scheduler) |
| `CHATBOT_*.md` | Chatbot (plan, sikkerhed, komponenter) |
| `EMAIL_MARKETING_SYSTEM_IMPLEMENTATION_PLAN.md` | E-mailmarkedsføring |
| `ELEMENTOR_MODALS_IMPLEMENTATION_PLAN.md` | Elementor-modaler |
| `KNOWLEDGE_BASE_IMPLEMENTATION_GUIDE.md` | Knowledge base |
| `NETWORKS_PAGE_README.md`, `NETWORKS_TAB_REFACTOR_PLAN.md` | Netværk |
| `PRODUCT_HISTORY_WIDGET_IMPLEMENTATION_PLAN.md` | Produkthistorik-widget |

---

## `tests/` – Testfiler

- PHPUnit unit- og integrations tests (f.eks. `Unit/ProductTransactionManagerTest.php` og andre under `tests/`).

---

## `vendor/` – Tredjeparts-afhængigheder

- **Composer:** `autoload.php`, `composer/` (autoload, classmap m.m.)
- **woocommerce/action-scheduler:** Brugt til planlagte jobs og køer

---

*Sidst opdateret: Marts 2025*
