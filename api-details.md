# Product Information Collection API Details

## Introduction
This document outlines the specifications for collecting product information from your online store. By following these guidelines, you can provide the necessary data for processing and storage by MetaSearch Vaib. This document serves as a general guide applicable to all types of products.

## Web Service (API) Structure

### 1. Product List Web Service
The web service address for retrieving the product list should be in the following format:

```
<api_url>/<...>?page=<page_number>&size=<page_size>
```

Where:
- `<api_url>`: The web service address (e.g., https://api.digikala.ir)
- `<page_number>`: Page number (starting from 1), e.g., page=7
- `<page_size>`: Number of products per page, e.g., size=50

For example, if Digikala wants to provide their product list web service and we want to receive 100 products from page 44, the address would be:

```
https://api.digikala.ir/products?page=44&size=100
```

Note 1: In the above address, we've put "products" in the empty space "<...>" which is completely optional and has no relation to Vaib.

The result of calling this web service should be a list of products in JSON format (an array of objects), with each object containing at least the following required fields:

1. Product ID (product-id)
   - Example: SKU3245 or 53457

2. Product Title (product-name)
   - Example: iPhone 13 Mobile Registered 256GB Not Active

3. Product Availability (product-available)
   - Example: True/False

Note 2: Ensure that product categorization is accurate and consistent with the product type (e.g., "Mobile", "Laptop", "Carpet").

### 2. Product Details Web Service
The web service address for retrieving details of each product should be in the following format:

```
<api_url>/<...>/<product_id>
```

Where:
- `<api_url>`: The web service address (e.g., https://api.digikala.ir)
- `<product_id>`: Product ID (received from calling web service 1.1)
   - Example: SKU3245 or 63453

Note 3: The empty part "<...>" of web service 1.2 should be the same as the empty part "<...>" in service 1.1. For example, if we used "products" above, we should use the same here.

The result of calling this web service should be the details of each product in JSON format, which can include the following fields (fields marked with * must be included):

1. Product ID * (product-id)
   - Example: SKU3245 or 53457

2. Product Title * (product-name)
   - Example: iPhone 13 Mobile Registered 256GB Not Active

3. Product Category * (product-category)
   - Example: mobile or Mobile or Smartphone

4. Product URL * (product-url)
   - Example: https://refah-kar.ir/mobile/iphone13$PID3245h

Note 4: The URL of each product should redirect to the product details page on your website when clicked by a user reviewing the purchase.

5. List of Product Image URLs * (product-images)
   - Example:
   ```
   [
     "https://refah-kar.ir/mobile/iphone13$PID3245/1.png",
     "https://refah-kar.ir/mobile/iphone13$PID3245/2.png",
     "https://refah-kar.ir/mobile/iphone13$PID3245/3.png"
   ]
   ```

6. Price Before Discount * (price-main)
   - Example: 62,000,000

7. Sale Price * (price-sale)
   - Example: 58,000,000

Note 5: Prices on the web service and the website should use the same unit. For example, if prices on the site are displayed in Rials, prices on the web service should also be sent in Rials.

8. Product Description * (product-description)
   - Example: "iPhone 13 CH mobile is Apple's new flagship that has been released with several new features and a dual camera. The iPhone 13 display is equipped with a Super Retina panel to provide excellent images to the user. This display has a very high resolution."

9. Brand (brand) (must be sent if available)
   - Example: Samsung

10. Model (model) (must be sent if available)
    - Example: A06

11. Product Specifications * (properties)
    - Example:
    ```
    [
      {"name":"Color","value":"Yellow"},
      {"name":"Resolution","value":"1200x800"},
      {"name":"Weight","value":"300 grams"},
      {"name":"Memory","value":"256 GB"},
      ...
    ]
    ```

Note 6: A list of product specifications in an array of key/value pairs. The more product detail fields, the better chance for end users to find this product quickly.

12. Product Variants (product-variant)
    If a product has multiple values for one specification (property), for example, iPhone 13 in Yellow and Blue, then define one specification as the main product according to the above provisions, and for other values of that specification, use variants. In this case, a specific product with a unique product address identifier can have different prices for different values of a specification. In this case, the name of the specification (property) used in the product specifications (properties) section should be included, and the price before discount and sale price for the new value of that specification should be sent.

- Variant Title (variant-title) which should be the product ID, Example: SKU3245 or 53457
- Main Product Price (variant-price-main), Example: 62,000,000
- Product Sale Price (variant-price-sale), Example: 58,000,000
- Product Specification (product-property) and its value, Example: {"name":"Color","value":"Blue"}

Example: iPhone 13 mobile in Yellow and Blue, where the Yellow model is defined as the main product and the Blue value will be defined in the variant format:

```
[
  {
    "variant-title":"SKU3245",
    "price-main": 1500000,
    "price-sale": 1400000,
    "properties": [
      {"name":"Color","value":"Blue"},
      {"name":"Warranty","value":"No Warranty"},
      ...
    ]
  }
]
```

Note 7: If it's possible to send more descriptive fields, these fields can be added with different names in JSON format, provided that the starred fields are definitely sent. Note that the more fields sent, the more searches the product will appear in.

Note 8: If you prefer, it's possible to receive information for both section 1 (product list) and section 2 (product details) in a single web service. In this case, product details should be added to the product list.

Note 9: There's no need to send comments and user reviews.

## Implementation Guide for WooCommerce
To implement these endpoints with WooCommerce REST API:

1. Use WooCommerce's built-in REST API endpoints:
   - `/wp-json/wc/v3/products` for product listing
   - `/wp-json/wc/v3/products/<id>` for product details

2. Create custom endpoints to format the data according to the specifications above.

3. Use pagination parameters in WooCommerce API:
   - `?page=<page_number>&per_page=<page_size>`

4. Map WooCommerce product fields to the required fields in this document.

5. For product variants, use WooCommerce's variation endpoints and map them to the required format.

## Standalone Implementation
If implementing without WooCommerce:

1. Create custom PHP endpoints that query your database directly.

2. Ensure proper caching for better performance.

3. Implement pagination logic manually.

4. Format the JSON response according to the specifications above.

5. Use proper authentication methods to secure your API endpoints.