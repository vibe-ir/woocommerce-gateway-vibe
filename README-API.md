# Vibe Product Information Collection API

This document outlines the API endpoints implemented for collecting product information from your WooCommerce store.

## API Endpoints

### 1. Product List Endpoint

**Endpoint:** `/wp-json/vibe/v1/products`

**Method:** GET

**Parameters:**
- `page`: Page number (starting from 1)
- `size`: Number of products per page

**Example Request:**
```
GET https://your-site.com/wp-json/vibe/v1/products?page=1&size=10
```

**Example Response:**
```json
[
  {
    "product-id": 123,
    "product-name": "Sample Product",
    "product-available": true
  },
  {
    "product-id": 124,
    "product-name": "Another Product",
    "product-available": false
  }
]
```

### 2. Product Details Endpoint

**Endpoint:** `/wp-json/vibe/v1/products/{product_id}`

**Method:** GET

**Parameters:**
- `product_id`: The ID of the product to retrieve details for

**Example Request:**
```
GET https://your-site.com/wp-json/vibe/v1/products/123
```

**Example Response:**
```json
{
  "product-id": 123,
  "product-name": "Sample Product",
  "product-category": "Electronics",
  "product-url": "https://your-site.com/product/sample-product",
  "product-images": [
    "https://your-site.com/wp-content/uploads/2023/01/product-image-1.jpg",
    "https://your-site.com/wp-content/uploads/2023/01/product-image-2.jpg"
  ],
  "price-main": "100.00",
  "price-sale": "80.00",
  "product-description": "This is a sample product description.",
  "brand": "Sample Brand",
  "model": "Model X",
  "properties": [
    {
      "name": "Color",
      "value": "Blue"
    },
    {
      "name": "Size",
      "value": "Medium"
    }
  ],
  "product-variant": [
    {
      "variant-title": 456,
      "price-main": "110.00",
      "price-sale": "90.00",
      "properties": [
        {
          "name": "Color",
          "value": "Red"
        }
      ]
    }
  ]
}
```

## Field Descriptions

### Product List Fields
- `product-id`: Unique identifier for the product
- `product-name`: Name/title of the product
- `product-available`: Boolean indicating if the product is in stock

### Product Details Fields
- `product-id`: Unique identifier for the product
- `product-name`: Name/title of the product
- `product-category`: Primary category of the product
- `product-url`: URL to the product page on your website
- `product-images`: Array of image URLs for the product
- `price-main`: Regular price of the product
- `price-sale`: Sale price of the product (same as regular price if no sale)
- `product-description`: Detailed description of the product
- `brand`: Brand name (if available)
- `model`: Model number or name (if available)
- `properties`: Array of product attributes/specifications
- `product-variant`: Array of product variations (for variable products)

## Requirements

### WordPress REST API
These endpoints are built on top of the WordPress REST API, which is enabled by default in WordPress. The endpoints will be available automatically when the plugin is activated, without requiring any additional configuration.

However, if the WordPress REST API has been disabled on your site (through plugins, custom code, or server configuration), these endpoints will not function. The plugin will display an admin notice if it detects that the REST API is disabled.

Requirements for the REST API to function properly:
- WordPress REST API must be enabled
- Pretty permalinks must be enabled (Settings > Permalinks)
- No plugins or custom code should be blocking REST API access

### WooCommerce
- WooCommerce must be installed and activated
- The plugin does not require the WooCommerce REST API to be enabled

## Authentication

The API supports optional API key authentication, which can be enabled in the WooCommerce settings.

### Enabling API Authentication

1. Go to WooCommerce > Settings > Vibe API
2. Check the "Enable API Authentication" option
3. Save changes
4. Copy the generated API key (or generate a new one if needed)

### Using API Authentication

When API authentication is enabled, you must include the API key in the `X-Vibe-API-Key` header of your requests:

```
GET /wp-json/vibe/v1/products HTTP/1.1
Host: your-site.com
X-Vibe-API-Key: your-api-key-here
```

If authentication is enabled and the API key is missing or invalid, the API will return a 401 Unauthorized error.

### Security Recommendations

- Always enable API authentication in production environments
- Regenerate the API key periodically
- Use HTTPS for all API requests
- Consider implementing additional security measures such as IP whitelisting

## Admin Interface

The plugin provides a user-friendly admin interface for managing the API settings and sharing endpoint information.

### API Settings

Access the API settings by navigating to WooCommerce > Settings > Vibe API. Here you can:

- Enable or disable API authentication
- View your current API key
- Generate a new API key

### Copying and Sharing Endpoints

The admin interface makes it easy to share API endpoint information with integration partners:

1. **Copy Endpoints**: Each endpoint URL has a "Copy" button that copies the URL to your clipboard with a single click.

2. **Share via Email**: The "Share via Email" button opens your default email client with a pre-populated message containing:
   - Both API endpoint URLs
   - The API key (if authentication is enabled)
   - Basic instructions for using the API

This makes it simple to share the necessary information with developers or integration partners without having to manually copy and format the details.

## Implementation Notes

- The API is built on top of the WordPress REST API and WooCommerce's data structures
- Field names follow the exact specifications required by the Vibe integration
- The implementation handles both simple and variable products
- Brand and model information is extracted from product attributes or taxonomies when available 