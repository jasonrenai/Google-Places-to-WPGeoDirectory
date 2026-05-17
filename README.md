# Google Places to GeoDirectory

A WordPress plugin that imports businesses from Google Places API and exports them in a format compatible with GeoDirectory.

**Developer:** [Jason Renai](https://github.com/jasonrenai) for [Spokencode](https://spokencode.com/)

## Features

- **Google Places API Integration**: Connect to Google Places API (New) to search for businesses
- **Flexible Search Options**: 
  - Text Search: Search by keywords, location, type, and rating
  - Nearby Search: Search by coordinates and radius
- **Export Functionality**: Export saved places to CSV or JSON format compatible with GeoDirectory
- **Admin Interface**: Clean, tabbed admin interface for easy management

## Requirements

- WordPress 5.0 or higher
- GeoDirectory plugin (must be installed and active)
- Google Places API key with Places API (New) enabled

## Installation

1. Upload the `google-places-to-geodirectory` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'GP to GeoDir' in the WordPress admin menu
4. Enter your Google Places API key in the API Settings tab

## Getting a Google Places API Key

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the "Places API (New)" for your project
4. Go to "Credentials" and create an API key
5. (Optional) Restrict the API key to Places API only for security

## Usage

### 1. API Settings Tab

- Enter your Google Places API key
- Test the connection to ensure it's working

### 2. Search Tab

**Text Search:**
- Enter a search query (e.g., "restaurants in New York")
- Optionally filter by region code, type, minimum rating
- Set maximum number of results

**Nearby Search:**
- Enter latitude and longitude coordinates
- Set search radius in meters
- Optionally filter by place type
- Set maximum number of results

After searching:
- Review the results
- Select places you want to export
- Click "Save Selected to Export List"

### 3. Export Tab

- View the number of saved places
- Choose export format (CSV or JSON)
- Select the GeoDirectory post type
- Click "Download Export File"

## Export Format

The plugin exports data in a format compatible with GeoDirectory's import functionality. The CSV includes:

- Basic WordPress fields (title, content, status, etc.)
- GeoDirectory location fields (address, city, region, country, zip, latitude, longitude)
- Contact information (phone, website, email)
- Categories and tags
- Custom fields (if configured in GeoDirectory)

## Notes

- The plugin uses the new Google Places API (v1)
- Make sure your API key has the Places API (New) enabled
- Rate limits apply based on your Google Cloud billing plan
- The export format may need adjustment based on your specific GeoDirectory configuration

## Support

For issues or questions, please check:
- GeoDirectory documentation for import format requirements
- Google Places API documentation for API usage

## Changelog

### 2.0.0
- Direct GeoDirectory import with AI descriptions (~300 words)
- Contact enrichment (Hunter/Apollo), website content for descriptions
- Manual category/tag assignment in import preview (post-import taxonomy supported)

### 1.0.0
- Initial release
- Google Places API integration
- Text and Nearby search
- CSV and JSON export
- Admin interface with tabs

