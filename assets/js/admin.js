/**
 * Admin JavaScript
 */

(function($) {
	'use strict';
	
	// Check if Google Maps is loaded
	function checkMapsLoaded(callback, maxAttempts) {
		maxAttempts = maxAttempts || 20;
		var attempts = 0;
		
		function check() {
			attempts++;
			if (typeof google !== 'undefined' && typeof google.maps !== 'undefined') {
				callback();
			} else if (attempts < maxAttempts) {
				setTimeout(check, 100);
			}
		}
		check();
	}
	
	$(document).ready(function() {
		
		var map, marker, comprehensiveMap, comprehensiveMarker, comprehensiveCircle;
		var defaultLat = 40.7128;
		var defaultLng = -74.0060;
		var lastSearchPlaces = [];
		var lastResultsMessage = '';
		var modalPlace = null;
		var modalPlaceIndex = null;
		var modalCategoryIds = [];
		var modalTagIds = [];
		var modalTaxonomyReviewed = false;
		var bulkImportRunning = false;
		var contactEnrichQueue = {};
		
		// Initialize map when nearby search is selected
		function initMap() {
			if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
				console.error('Google Maps API not loaded');
				return;
			}
			
			var mapContainer = document.getElementById('gptg-location-map');
			if (!mapContainer) {
				return;
			}
			
			// Get current lat/lng or use defaults
			var lat = parseFloat($('#latitude').val()) || defaultLat;
			var lng = parseFloat($('#longitude').val()) || defaultLng;
			
			var mapOptions = {
				center: { lat: lat, lng: lng },
				zoom: 10,
				mapTypeId: google.maps.MapTypeId.ROADMAP
			};
			
			map = new google.maps.Map(mapContainer, mapOptions);
			
			// Create marker
			marker = new google.maps.Marker({
				position: { lat: lat, lng: lng },
				map: map,
				draggable: true,
				title: 'Drag to set location'
			});
			
			// Update lat/lng when marker is dragged
			marker.addListener('dragend', function() {
				var position = marker.getPosition();
				$('#latitude').val(position.lat());
				$('#longitude').val(position.lng());
			});
			
			// Update lat/lng and move marker when map is clicked
			map.addListener('click', function(event) {
				var lat = event.latLng.lat();
				var lng = event.latLng.lng();
				marker.setPosition({ lat: lat, lng: lng });
				$('#latitude').val(lat);
				$('#longitude').val(lng);
			});
			
			// Update marker position when lat/lng inputs change manually
			$('#latitude, #longitude').on('change', function() {
				var lat = parseFloat($('#latitude').val());
				var lng = parseFloat($('#longitude').val());
				if (!isNaN(lat) && !isNaN(lng)) {
					var position = { lat: lat, lng: lng };
					marker.setPosition(position);
					map.setCenter(position);
				}
			});
		}
		
		// Convert miles to meters
		function milesToMeters(miles) {
			return miles * 1609.34;
		}
		
		// Convert meters to miles
		function metersToMiles(meters) {
			return meters / 1609.34;
		}
		
		// Update radius in meters when miles input changes
		$('#radius_miles').on('input change', function() {
			var miles = parseFloat($(this).val()) || 0;
			var meters = Math.round(milesToMeters(miles));
			$('#radius').val(meters);
		});
		
		// Initialize radius conversion
		if ($('#radius_miles').length) {
			$('#radius_miles').trigger('change');
		}
		
		// Initialize comprehensive map
		function initComprehensiveMap() {
			if (typeof google === 'undefined' || typeof google.maps === 'undefined') {
				return;
			}
			
			var mapContainer = document.getElementById('gptg-comprehensive-map');
			if (!mapContainer) {
				return;
			}
			
			var lat = parseFloat($('#comprehensive_latitude').val()) || defaultLat;
			var lng = parseFloat($('#comprehensive_longitude').val()) || defaultLng;
			var radiusMiles = parseFloat($('#comprehensive_radius_miles').val()) || 5;
			var radiusMeters = radiusMiles * 1609.34;
			
			// Set default values if empty
			if (!$('#comprehensive_latitude').val()) {
				$('#comprehensive_latitude').val(lat);
			}
			if (!$('#comprehensive_longitude').val()) {
				$('#comprehensive_longitude').val(lng);
			}
			
			var mapOptions = {
				center: { lat: lat, lng: lng },
				zoom: 11,
				mapTypeId: google.maps.MapTypeId.ROADMAP
			};
			
			comprehensiveMap = new google.maps.Map(mapContainer, mapOptions);
			
			// Create marker
			comprehensiveMarker = new google.maps.Marker({
				position: { lat: lat, lng: lng },
				map: comprehensiveMap,
				draggable: true,
				title: 'Drag to set center'
			});
			
			// Create circle
			comprehensiveCircle = new google.maps.Circle({
				strokeColor: '#FF0000',
				strokeOpacity: 0.8,
				strokeWeight: 2,
				fillColor: '#FF0000',
				fillOpacity: 0.15,
				map: comprehensiveMap,
				center: { lat: lat, lng: lng },
				radius: radiusMeters
			});
			
			// Update on marker drag
			comprehensiveMarker.addListener('dragend', function() {
				var position = comprehensiveMarker.getPosition();
				$('#comprehensive_latitude').val(position.lat());
				$('#comprehensive_longitude').val(position.lng());
				updateComprehensiveCircle();
			});
			
			// Update on map click
			comprehensiveMap.addListener('click', function(event) {
				var lat = event.latLng.lat();
				var lng = event.latLng.lng();
				comprehensiveMarker.setPosition({ lat: lat, lng: lng });
				$('#comprehensive_latitude').val(lat);
				$('#comprehensive_longitude').val(lng);
				updateComprehensiveCircle();
			});
			
			// Update circle when radius changes
			$('#comprehensive_radius_miles').on('input change', function() {
				updateComprehensiveCircle();
			});
		}
		
		function updateComprehensiveCircle() {
			if (!comprehensiveCircle || !comprehensiveMarker) return;
			
			var radiusMiles = parseFloat($('#comprehensive_radius_miles').val()) || 5;
			var radiusMeters = radiusMiles * 1609.34;
			var position = comprehensiveMarker.getPosition();
			
			comprehensiveCircle.setCenter(position);
			comprehensiveCircle.setRadius(radiusMeters);
			$('#comprehensive_radius').val(Math.round(radiusMeters));
			
			// Update cell radius in meters
			var cellRadiusMiles = parseFloat($('#comprehensive_cell_radius').val()) || 1.5;
			$('#comprehensive_cell_radius_meters').val(Math.round(cellRadiusMiles * 1609.34));
		}

		// Enable only the active search section's inputs (avoids hidden-field validation/serialize issues)
		function setSearchMethodInputs(method) {
			var sections = {
				text: '#text-search-options',
				nearby: '#nearby-search-options',
				comprehensive: '#comprehensive-search-options'
			};

			$.each(sections, function(key, selector) {
				var $section = $(selector);
				var enabled = key === method;
				$section.find('input, select, textarea').prop('disabled', !enabled);
			});
		}
		
		// Toggle search method options
		$('input[name="search_method"]').on('change', function() {
			var method = $(this).val();
			if (method === 'text') {
				$('#text-search-options').show();
				$('#nearby-search-options').hide();
				$('#comprehensive-search-options').hide();
			} else if (method === 'nearby') {
				$('#text-search-options').hide();
				$('#nearby-search-options').show();
				$('#comprehensive-search-options').hide();
				// Initialize map when nearby search is shown
				checkMapsLoaded(function() {
					setTimeout(initMap, 100);
				});
			} else if (method === 'comprehensive') {
				$('#text-search-options').hide();
				$('#nearby-search-options').hide();
				$('#comprehensive-search-options').show();
				// Initialize comprehensive map
				checkMapsLoaded(function() {
					setTimeout(initComprehensiveMap, 100);
				});
			}
			setSearchMethodInputs(method);
		});
		
		// Initialize map if search method is already selected
		var selectedMethod = $('input[name="search_method"]:checked').val() || 'text';
		setSearchMethodInputs(selectedMethod);
		if (selectedMethod === 'nearby') {
			checkMapsLoaded(function() {
				setTimeout(initMap, 100);
			});
		} else if (selectedMethod === 'comprehensive') {
			checkMapsLoaded(function() {
				setTimeout(initComprehensiveMap, 100);
			});
		}
		
		// Test Hunter connection
		$('#gptg-test-hunter').on('click', function(e) {
			e.preventDefault();
			var $button = $(this);
			var $result = $('#gptg-test-hunter-result');

			$button.prop('disabled', true).text(gptgAdmin.i18n.testingHunter);
			$result.removeClass('success error').html('');

			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_test_hunter',
					nonce: gptgAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						$result.addClass('success').html(response.data.message);
					} else {
						$result.addClass('error').html((response.data && response.data.message) ? response.data.message : gptgAdmin.i18n.error);
					}
				},
				error: function() {
					$result.addClass('error').html(gptgAdmin.i18n.error);
				},
				complete: function() {
					$button.prop('disabled', false).text(gptgAdmin.i18n.testHunter);
				}
			});
		});

		// Test API connection
		$('#gptg-test-api').on('click', function(e) {
			e.preventDefault();
			var $button = $(this);
			var $result = $('#gptg-api-test-result');
			
			$button.prop('disabled', true).text(gptgAdmin.i18n.searching);
			$result.removeClass('success error').html('');
			
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_test_api',
					nonce: gptgAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						$result.addClass('success').html(response.data.message);
					} else {
						$result.addClass('error').html(response.data.message || gptgAdmin.i18n.error);
					}
				},
				error: function() {
					$result.addClass('error').html(gptgAdmin.i18n.error);
				},
				complete: function() {
					$button.prop('disabled', false).text('Test Connection');
				}
			});
		});
		
		// Search places
		$('#gptg-search-form').on('submit', function(e) {
			e.preventDefault();
			
			var $form = $(this);
			var $results = $('#gptg-search-results');
			var $resultsList = $('#gptg-results-list');
			var $submit = $form.find('button[type="submit"]');
			var $progress = $('<div class="gptg-progress">' + gptgAdmin.i18n.searching + ' <span class="gptg-progress-text"></span></div>');
			
			$submit.prop('disabled', true).text(gptgAdmin.i18n.searching);
			$results.hide();
			$resultsList.html('');
			$resultsList.append($progress);
			$results.show();
			
			var searchMethod = $('input[name="search_method"]:checked').val() || 'text';
			setSearchMethodInputs(searchMethod);
			var action = 'gptg_search_places';
			
			// Convert radius from miles to meters before submitting
			if ($('#radius_miles').length) {
				var miles = parseFloat($('#radius_miles').val()) || 0;
				$('#radius').val(Math.round(miles * 1609.34));
			}
			
			// Handle comprehensive search
			if (searchMethod === 'comprehensive') {
				action = 'gptg_search_comprehensive';
				
				// Validate coordinates
				var compLat = parseFloat($('#comprehensive_latitude').val());
				var compLng = parseFloat($('#comprehensive_longitude').val());
				
				if (isNaN(compLat) || isNaN(compLng)) {
					alert('Please set the center location on the map first.');
					$submit.prop('disabled', false).text('Search Places');
					return false;
				}
				
				// Convert comprehensive radius
				var compRadiusMiles = parseFloat($('#comprehensive_radius_miles').val()) || 5;
				$('#comprehensive_radius').val(Math.round(compRadiusMiles * 1609.34));
				// Convert cell radius
				var cellRadiusMiles = parseFloat($('#comprehensive_cell_radius').val()) || 1.5;
				$('#comprehensive_cell_radius_meters').val(Math.round(cellRadiusMiles * 1609.34));
			}
			
			var formData = $form.serialize();
			formData += '&action=' + action + '&nonce=' + gptgAdmin.nonce;
			
			// Get requested count for progress display
			var requestedCount = 20;
			if ($('#max_result_count').length) {
				requestedCount = parseInt($('#max_result_count').val()) || 20;
			} else if ($('#nearby_max_result_count').length) {
				requestedCount = parseInt($('#nearby_max_result_count').val()) || 20;
			}
			requestedCount = Math.min(requestedCount, 60);
			
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: formData,
				timeout: 600000, // 10 minutes timeout for comprehensive searches
				beforeSend: function() {
					$progress.find('.gptg-progress-text').text('(This may take a moment for large result sets...)');
				},
				success: function(response) {
					$progress.remove();
					if (response.success) {
						var message = '';
						if (response.data.message) {
							message = response.data.message;
						} else {
							message = 'Found ' + response.data.count + ' unique place(s)';
							if (response.data.pages_fetched && response.data.pages_fetched > 1) {
								message += ' (fetched ' + response.data.pages_fetched + ' page' + (response.data.pages_fetched > 1 ? 's' : '') + ')';
							}
							if (response.data.total_cells) {
								message += ' from ' + response.data.total_cells + ' grid cell' + (response.data.total_cells > 1 ? 's' : '');
							}
							if (response.data.details_errors && response.data.details_errors > 0) {
								message += ' (' + response.data.details_errors + ' place(s) used basic data; details unavailable)';
							}
						}
						setSearchResults(response.data.places, message);
					} else {
						var errorMsg = response.data.message || gptgAdmin.i18n.error;
						if (response.data.error_code) {
							errorMsg += ' (Error: ' + response.data.error_code + ')';
						}
						alert(errorMsg);
					}
				},
				error: function(xhr, status, error) {
					$progress.remove();
					if (status === 'timeout') {
						alert('Request timed out. Try reducing the number of results or check your connection.');
					} else {
						alert(gptgAdmin.i18n.error);
					}
				},
				complete: function() {
					$submit.prop('disabled', false).text('Search Places');
				}
			});
		});
		

		function setSearchResults(places, message) {
			if (places && !Array.isArray(places)) {
				places = Object.values(places);
			}
			lastSearchPlaces = places || [];
			lastResultsMessage = message || '';
			contactEnrichQueue = {};
			$('#gptg-search-results').show();
			if (lastSearchPlaces.length) {
				$('#gptg-results-toolbar').show();
			} else {
				$('#gptg-results-toolbar').hide();
			}
			applyResultsView();
			if (lastSearchPlaces.length && gptgAdmin.contactEnabled) {
				enrichSearchContactsSequential();
			}
		}

		function getContactBadgeSvg(type) {
			var svgAttrs = ' class="gptg-badge-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
			switch (type) {
				case 'email':
					return '<svg' + svgAttrs + '><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>';
				case 'facebook':
					return '<svg' + svgAttrs + ' fill="currentColor" stroke="none"><path d="M14 8h3V5h-3c-2.8 0-5 2.2-5 5v2H6v3h3v8h3v-8h3l1-3h-4v-2c0-.6.4-1 1-1z"/></svg>';
				case 'twitter':
					return '<svg' + svgAttrs + ' fill="currentColor" stroke="none"><path d="M18.244 3H21.5l-7.5 8.57L22.5 21h-6.31l-4.94-6.46L5.5 21H2.24l8.04-9.18L1.5 3h6.46l4.47 5.91L18.24 3zm-2.2 16.2h1.72L7.04 4.65H5.2l10.84 14.55z"/></svg>';
				case 'instagram':
					return '<svg' + svgAttrs + '><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/></svg>';
				case 'linkedin':
					return '<svg' + svgAttrs + ' fill="currentColor" stroke="none"><path d="M6 9H2v12h4V9zm2-4a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM8 9H4v12h4v-6c0-2 1-3 3-3s3 1 3 3v6h4v-7c0-3.5-2-6-6-6-2.2 0-3.6 1-4.2 2.2V9H8z"/></svg>';
				case 'pending':
					return '<svg' + svgAttrs + '><circle cx="12" cy="12" r="9" stroke-dasharray="14 42"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.9s" repeatCount="indefinite"/></circle></svg>';
				case 'none':
					return '<svg' + svgAttrs + '><path d="M5 12h14"/></svg>';
				default:
					return '';
			}
		}

		function renderContactBadgesHtml(place, index) {
			var c = getContact(place);
			var website = place && place.websiteUri;
			var state = contactEnrichQueue[index];
			var html = '<span class="gptg-contact-badges" data-place-index="' + index + '">';
			if (state === 'pending') {
				html += '<span class="gptg-badge gptg-badge-pending" title="Looking up contact">' + getContactBadgeSvg('pending') + '</span>';
			} else if (c.email) {
				html += '<span class="gptg-badge gptg-badge-email" title="Email found">' + getContactBadgeSvg('email') + '</span>';
			}
			if (c.facebook) {
				html += '<span class="gptg-badge gptg-badge-facebook" title="Facebook">' + getContactBadgeSvg('facebook') + '</span>';
			}
			if (c.twitter) {
				html += '<span class="gptg-badge gptg-badge-twitter" title="X/Twitter">' + getContactBadgeSvg('twitter') + '</span>';
			}
			if (c.instagram) {
				html += '<span class="gptg-badge gptg-badge-instagram" title="Instagram">' + getContactBadgeSvg('instagram') + '</span>';
			}
			if (c.linkedin) {
				html += '<span class="gptg-badge gptg-badge-linkedin" title="LinkedIn">' + getContactBadgeSvg('linkedin') + '</span>';
			}
			if (website && state === 'done' && !c.email && !c.facebook && !c.twitter && !c.instagram && !c.linkedin) {
				html += '<span class="gptg-badge gptg-badge-none" title="No contact data found">' + getContactBadgeSvg('none') + '</span>';
			}
			html += '</span>';
			return html;
		}

		function passesFilters(place) {
			if ($('#gptg-filter-website').is(':checked') && !place.websiteUri) {
				return false;
			}
			if ($('#gptg-filter-phone').is(':checked') && !(place.nationalPhoneNumber || place.internationalPhoneNumber)) {
				return false;
			}
			var c = getContact(place);
			if ($('#gptg-filter-email').is(':checked') && !c.email) {
				return false;
			}
			if ($('#gptg-filter-facebook').is(':checked') && !c.facebook) {
				return false;
			}
			if ($('#gptg-filter-twitter').is(':checked') && !c.twitter) {
				return false;
			}
			if ($('#gptg-filter-instagram').is(':checked') && !c.instagram) {
				return false;
			}
			if ($('#gptg-filter-linkedin').is(':checked') && !c.linkedin) {
				return false;
			}
			var minRating = parseFloat($('#gptg-filter-min-rating').val());
			if (!isNaN(minRating) && minRating > 0) {
				var rating = parseFloat(place.rating);
				if (isNaN(rating) || rating < minRating) {
					return false;
				}
			}
			return true;
		}

		function getFilteredSortedIndices() {
			var indices = [];
			for (var i = 0; i < lastSearchPlaces.length; i++) {
				if (passesFilters(lastSearchPlaces[i])) {
					indices.push(i);
				}
			}
			var sort = $('#gptg-results-sort').val() || 'name_asc';
			indices.sort(function(a, b) {
				var pa = lastSearchPlaces[a];
				var pb = lastSearchPlaces[b];
				var na = getPlaceName(pa).toLowerCase();
				var nb = getPlaceName(pb).toLowerCase();
				if (sort === 'name_asc') {
					return na.localeCompare(nb);
				}
				if (sort === 'name_desc') {
					return nb.localeCompare(na);
				}
				if (sort === 'rating_desc') {
					return (parseFloat(pb.rating) || 0) - (parseFloat(pa.rating) || 0);
				}
				if (sort === 'reviews_desc') {
					return (parseInt(pb.userRatingCount, 10) || 0) - (parseInt(pa.userRatingCount, 10) || 0);
				}
				return 0;
			});
			return indices;
		}

		function applyResultsView() {
			displaySearchResults(getFilteredSortedIndices(), lastResultsMessage);
		}

		function enrichSearchContactsSequential(onComplete) {
			var indices = [];
			for (var i = 0; i < lastSearchPlaces.length; i++) {
				if (lastSearchPlaces[i].websiteUri) {
					indices.push(i);
					contactEnrichQueue[i] = 'pending';
				}
			}
			if (!indices.length) {
				if (typeof onComplete === 'function') {
					onComplete();
				}
				return;
			}
			var total = indices.length;
			var done = 0;
			var $progress = $('#gptg-contact-enrich-progress');
			var $fill = $progress.find('.gptg-progress-fill');
			var $text = $('#gptg-contact-enrich-progress-text');
			$progress.show();

			function updateProgress() {
				var pct = total ? Math.round((done / total) * 100) : 0;
				$fill.css('width', pct + '%');
				$text.text(gptgAdmin.i18n.enrichingContacts + ' ' + done + ' ' + gptgAdmin.i18n.of + ' ' + total);
			}

			function next() {
				if (done >= total) {
					$progress.hide();
					applyResultsView();
					if (typeof onComplete === 'function') {
						onComplete();
					}
					return;
				}
				var idx = indices[done];
				var place = lastSearchPlaces[idx];
				updateProgress();
				$.ajax({
					url: gptgAdmin.ajaxUrl,
					type: 'POST',
					data: {
						action: 'gptg_enrich_contact',
						nonce: gptgAdmin.nonce,
						place_id: getPlaceId(place),
						place: JSON.stringify(place)
					},
					success: function(response) {
						if (response.success && response.data.place) {
							lastSearchPlaces[idx] = response.data.place;
						} else if (response.success && response.data.contact) {
							lastSearchPlaces[idx].gptgContact = response.data.contact;
						}
					},
					complete: function() {
						contactEnrichQueue[idx] = 'done';
						done++;
						applyResultsView();
						setTimeout(next, 150);
					}
				});
			}
			next();
		}

		function displaySearchResults(indices, message) {
			var $list = $('#gptg-results-list');
			$list.html('');

			if (!indices || !indices.length) {
				var emptyText = message || gptgAdmin.i18n.noResults;
				if (lastSearchPlaces.length) {
					emptyText = 'No places match the current filters.';
				}
				$list.html('<p class="gptg-no-results">' + escapeHtml(emptyText) + '</p>');
				updateSelectAllCheckbox();
				return;
			}

			if (message) {
				$list.append('<div class="gptg-results-message">' + escapeHtml(message) + '</div>');
			}

			indices.forEach(function(index) {
				var place = lastSearchPlaces[index];
				if (!place) {
					return;
				}
				var name = getPlaceName(place);
				var address = place.formattedAddress || '';
				var rating = place.rating ? place.rating + ' stars' : '';
				var ratingCount = place.userRatingCount ? '(' + place.userRatingCount + ' reviews)' : '';
				var phone = place.nationalPhoneNumber || place.internationalPhoneNumber || '';
				var website = place.websiteUri || '';

				var html = '<div class="gptg-place-item" data-place-index="' + index + '">';
				html += '<input type="checkbox" class="gptg-place-checkbox" data-place-index="' + index + '" />';
				html += '<div class="gptg-place-info gptg-place-clickable">';
				html += '<div class="gptg-place-name">' + escapeHtml(name) + ' ' + renderContactBadgesHtml(place, index) + '</div>';
				if (address) {
					html += '<div class="gptg-place-address">' + escapeHtml(address) + '</div>';
				}
				html += '<div class="gptg-place-details">';
				if (rating) {
					html += '<span>⭐ ' + escapeHtml(rating) + ' ' + escapeHtml(ratingCount) + '</span>';
				}
				if (phone) {
					html += '<span>📞 ' + escapeHtml(phone) + '</span>';
				}
				if (website) {
					html += '<span>🌐 <a href="' + escapeHtml(website) + '" target="_blank" rel="noopener" onclick="event.stopPropagation();">Website</a></span>';
				}
				html += '</div>';
				html += '</div>';
				html += '<div class="gptg-place-actions">';
				html += '<button type="button" class="button button-small gptg-row-import" data-place-index="' + index + '">Import</button>';
				html += '<button type="button" class="button button-small gptg-row-details" data-place-index="' + index + '">Details</button>';
				html += '<span class="gptg-import-status" data-place-index="' + index + '"></span>';
				html += '</div>';
				html += '</div>';

				$list.append(html);
			});

			updateSelectAllCheckbox();
		}

		$('#gptg-results-sort, #gptg-filter-min-rating').on('change', function() {
			if (lastSearchPlaces.length) {
				applyResultsView();
			}
		});
		$('#gptg-apply-filters').on('click', function() {
			applyResultsView();
		});
		$('#gptg-clear-filters').on('click', function() {
			$('#gptg-results-toolbar input[type="checkbox"]').prop('checked', false);
			$('#gptg-filter-min-rating').val('');
			applyResultsView();
		});

		// Update select all checkbox state
		function updateSelectAllCheckbox() {
			var $checkboxes = $('.gptg-place-checkbox');
			var $selectAll = $('#gptg-select-all-checkbox');
			
			if ($checkboxes.length === 0) {
				$selectAll.prop('checked', false).prop('indeterminate', false);
				return;
			}
			
			var checkedCount = $checkboxes.filter(':checked').length;
			
			if (checkedCount === 0) {
				$selectAll.prop('checked', false).prop('indeterminate', false);
			} else if (checkedCount === $checkboxes.length) {
				$selectAll.prop('checked', true).prop('indeterminate', false);
			} else {
				$selectAll.prop('checked', false).prop('indeterminate', true);
			}
		}
		
		// Select all checkbox handler
		$('#gptg-select-all-checkbox').on('change', function() {
			$('.gptg-place-checkbox').prop('checked', $(this).is(':checked'));
		});
		
		// Update select all checkbox when individual checkboxes change
		$(document).on('change', '.gptg-place-checkbox', function() {
			updateSelectAllCheckbox();
		});
		
		// Select all places
		$('#gptg-select-all').on('click', function() {
			$('.gptg-place-checkbox').prop('checked', true);
			$('#gptg-select-all-checkbox').prop('checked', true).prop('indeterminate', false);
		});
		
		// Deselect all places
		$('#gptg-deselect-all').on('click', function() {
			$('.gptg-place-checkbox').prop('checked', false);
			$('#gptg-select-all-checkbox').prop('checked', false).prop('indeterminate', false);
		});
		
		// Save selected places
		$('#gptg-save-selected').on('click', function() {
			var selectedPlaces = [];
			$('.gptg-place-checkbox:checked').each(function() {
				var index = parseInt($(this).attr('data-place-index'), 10);
				if (!isNaN(index) && lastSearchPlaces[index]) {
					selectedPlaces.push(lastSearchPlaces[index]);
				}
			});
			
			if (selectedPlaces.length === 0) {
				alert('Please select at least one place.');
				return;
			}
			
			var $button = $(this);
			$button.prop('disabled', true).text('Saving...');
			
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_save_places',
					nonce: gptgAdmin.nonce,
					places: JSON.stringify(selectedPlaces)
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						$('#gptg-places-count').text(response.data.total);
					} else {
						alert(response.data.message || gptgAdmin.i18n.error);
					}
				},
				error: function() {
					alert(gptgAdmin.i18n.error);
				},
				complete: function() {
					$button.prop('disabled', false).text('Save to Export List');
				}
			});
		});
		
		// Clear all places
		$('#gptg-clear-places').on('click', function() {
			if (!confirm('Are you sure you want to clear all saved places?')) {
				return;
			}
			
			var $button = $(this);
			$button.prop('disabled', true).text('Clearing...');
			
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_clear_places',
					nonce: gptgAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						$('#gptg-places-count').text('0');
						location.reload();
					} else {
						alert(response.data.message || gptgAdmin.i18n.error);
					}
				},
				error: function() {
					alert(gptgAdmin.i18n.error);
				},
				complete: function() {
					$button.prop('disabled', false).text('Clear All Places');
				}
			});
		});
		
		// Export places
		$('#gptg-export-form').on('submit', function(e) {
			e.preventDefault();
			
			var $form = $(this);
			var formData = $form.serialize();
			formData += '&action=gptg_export_places&nonce=' + gptgAdmin.nonce;
			
			// Create a form and submit it to trigger download
			var $hiddenForm = $('<form>', {
				'method': 'POST',
				'action': gptgAdmin.ajaxUrl,
				'target': '_blank'
			});
			
			formData.split('&').forEach(function(param) {
				var parts = param.split('=');
				if (parts.length === 2) {
					$hiddenForm.append($('<input>', {
						'type': 'hidden',
						'name': decodeURIComponent(parts[0]),
						'value': decodeURIComponent(parts[1])
					}));
				}
			});
			
			$('body').append($hiddenForm);
			$hiddenForm.submit();
			$hiddenForm.remove();
		});
		
		// Escape HTML
		function escapeHtml(text) {
			if (text === null || text === undefined) {
				return '';
			}
			text = String(text);
			var map = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			};
			return text.replace(/[&<>"']/g, function(m) { return map[m]; });
		}

		function getPlaceName(place) {
			if (!place || !place.displayName) {
				return 'Unknown';
			}
			if (typeof place.displayName === 'string') {
				return place.displayName;
			}
			return place.displayName.text || 'Unknown';
		}

		function getPlaceId(place) {
			return place && place.id ? place.id : '';
		}

		function setImportStatus(index, text, type) {
			var $el = $('.gptg-import-status[data-place-index="' + index + '"]');
			$el.removeClass('success error duplicate').addClass(type || '').text(text || '');
		}

		function openPlaceModal(index) {
			var place = lastSearchPlaces[index];
			if (!place) {
				return;
			}
			modalPlaceIndex = index;
			modalPlace = place;
			modalCategoryIds = [];
			modalTagIds = [];
			modalTaxonomyReviewed = false;

			var $modal = $('#gptg-place-modal');
			$modal.show().attr('aria-hidden', 'false');
			$('#gptg-modal-loading').show();
			$('#gptg-modal-content').hide().empty();
			$('body').addClass('gptg-modal-open');

			var placeId = getPlaceId(place);
			if (!placeId) {
				renderModalContent(place);
				updateModalContactStatus(place);
				setupModalTaxonomies(place, index);
				return;
			}

			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_get_place_details',
					nonce: gptgAdmin.nonce,
					place_id: placeId
				},
				success: function(response) {
					if (response.success && response.data.place) {
						modalPlace = response.data.place;
						lastSearchPlaces[index] = modalPlace;
						renderModalContent(modalPlace);
						updateModalContactStatus(modalPlace);
						setupModalTaxonomies(modalPlace, index);
					} else {
						renderModalContent(place);
						updateModalContactStatus(place);
						setupModalTaxonomies(place, index);
						alert(response.data && response.data.message ? response.data.message : gptgAdmin.i18n.error);
					}
				},
				error: function() {
					renderModalContent(place);
					updateModalContactStatus(place);
					setupModalTaxonomies(place, index);
					alert(gptgAdmin.i18n.error);
				}
			});
		}

		function closePlaceModal() {
			$('#gptg-place-modal').hide().attr('aria-hidden', 'true');
			$('body').removeClass('gptg-modal-open');
			modalPlace = null;
			modalPlaceIndex = null;
		}

		function getContact(place) {
			return (place && place.gptgContact) ? place.gptgContact : {};
		}

		function needsContactEnrichment(place) {
			if (!gptgAdmin.contactEnabled) {
				return false;
			}
			if (!place || !place.websiteUri) {
				return false;
			}
			var c = getContact(place);
			var fields = ['email', 'facebook', 'twitter', 'instagram', 'linkedin'];
			for (var i = 0; i < fields.length; i++) {
				if (!c[fields[i]]) {
					return true;
				}
			}
			return false;
		}

		function loadModalContactEnrichment(place, index, force) {
			if (!gptgAdmin.contactEnabled) {
				return;
			}
			if (!place.websiteUri) {
				$('#gptg-contact-enrich-status').text(gptgAdmin.i18n.contactNoWebsite);
				return;
			}
			if (!force && !needsContactEnrichment(place)) {
				fillContactForm(getContact(place));
				updateContactEnrichStatus(getContact(place), false);
				return;
			}
			$('#gptg-contact-enrich-status').text(gptgAdmin.i18n.enrichingContact);
			$('#gptg-contact-retry').prop('disabled', true);
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_enrich_contact',
					nonce: gptgAdmin.nonce,
					place_id: getPlaceId(place),
					place: JSON.stringify(place),
					force: force ? '1' : '0'
				},
				success: function(response) {
					if (response.success) {
						if (response.data.place) {
							modalPlace = response.data.place;
							lastSearchPlaces[index] = modalPlace;
						}
						var contact = response.data.contact || getContact(modalPlace);
						fillContactForm(contact);
						var status = response.data.message || '';
						if (!status && response.data.error_detail) {
							status = (response.data.provider || 'Contact') + ': ' + response.data.error_detail;
						}
						if (response.data.domain_used && status.indexOf(response.data.domain_used) === -1) {
							status = status ? status : response.data.domain_used;
						}
						$('#gptg-contact-enrich-status').text(status);
					} else {
						var errMsg = response.data && response.data.message ? response.data.message : gptgAdmin.i18n.error;
						if (response.data && response.data.error_detail) {
							errMsg += ' — ' + response.data.error_detail;
						}
						$('#gptg-contact-enrich-status').text(errMsg);
					}
				},
				error: function() {
					$('#gptg-contact-enrich-status').text(gptgAdmin.i18n.error);
				},
				complete: function() {
					$('#gptg-contact-retry').prop('disabled', false);
				}
			});
		}

		function updateContactEnrichStatus(contact, cached) {
			if (!contact || !contact.source) {
				return;
			}
			var status = contact.source.charAt(0).toUpperCase() + contact.source.slice(1);
			if (contact.fetched_at) {
				status += ' — ' + contact.fetched_at;
			}
			if (cached) {
				status += ' (cached)';
			}
			$('#gptg-contact-enrich-status').text(status);
		}

		function fillContactForm(contact) {
			contact = contact || {};
			var fields = ['email', 'facebook', 'twitter', 'instagram', 'linkedin'];
			fields.forEach(function(field) {
				var $el = $('#gptg-contact-' + field);
				if ($el.length && !$el.val() && contact[field]) {
					$el.val(contact[field]);
				}
			});
		}

		function collectModalContact() {
			return {
				email: $('#gptg-contact-email').val() || '',
				facebook: $('#gptg-contact-facebook').val() || '',
				twitter: $('#gptg-contact-twitter').val() || '',
				instagram: $('#gptg-contact-instagram').val() || '',
				linkedin: $('#gptg-contact-linkedin').val() || ''
			};
		}

		function applyContactToPlace(place) {
			place = place || modalPlace;
			if (!place) {
				return place;
			}
			var collected = collectModalContact();
			place.gptgContact = place.gptgContact || {};
			['email', 'facebook', 'twitter', 'instagram', 'linkedin'].forEach(function(key) {
				if (collected[key]) {
					place.gptgContact[key] = collected[key];
				}
			});
			return place;
		}

		function renderModalContent(place) {
			$('#gptg-modal-loading').hide();
			var name = getPlaceName(place);
			var contact = getContact(place);
			var html = '<h2 class="gptg-modal-title">' + escapeHtml(name) + '</h2>';
			html += '<p class="gptg-modal-note">' + escapeHtml(gptgAdmin.i18n.noEmailNote) + '</p>';

			html += '<div class="gptg-modal-section"><h3>Overview</h3>';
			if (place.editorialSummary && place.editorialSummary.text) {
				html += '<p>' + escapeHtml(place.editorialSummary.text) + '</p>';
			} else if (place.generativeSummary && place.generativeSummary.overview && place.generativeSummary.overview.text) {
				html += '<p>' + escapeHtml(place.generativeSummary.overview.text) + '</p>';
			}
			if (place.rating) {
				html += '<p>⭐ ' + escapeHtml(place.rating) + ' (' + escapeHtml(place.userRatingCount || 0) + ' reviews)</p>';
			}
			if (place.primaryType || (place.types && place.types.length)) {
				html += '<p><strong>Types:</strong> ' + escapeHtml((place.types || []).join(', ')) + '</p>';
			}
			if (place.businessStatus) {
				html += '<p><strong>Status:</strong> ' + escapeHtml(place.businessStatus) + '</p>';
			}
			html += '</div>';

			html += '<div class="gptg-modal-section"><h3>Location</h3>';
			html += '<p>' + escapeHtml(place.formattedAddress || '') + '</p>';
			if (place.location) {
				html += '<p>' + escapeHtml(place.location.latitude) + ', ' + escapeHtml(place.location.longitude) + '</p>';
			}
			html += '</div>';

			html += '<div class="gptg-modal-section"><h3>Google contact</h3>';
			if (place.nationalPhoneNumber || place.internationalPhoneNumber) {
				html += '<p>📞 ' + escapeHtml(place.nationalPhoneNumber || place.internationalPhoneNumber) + '</p>';
			}
			if (place.websiteUri) {
				html += '<p>🌐 <a href="' + escapeHtml(place.websiteUri) + '" target="_blank" rel="noopener">' + escapeHtml(place.websiteUri) + '</a></p>';
			}
			if (place.googleMapsUri) {
				html += '<p><a href="' + escapeHtml(place.googleMapsUri) + '" target="_blank" rel="noopener">Open in Google Maps</a></p>';
			}
			html += '</div>';

			html += '<div id="gptg-modal-contact-enrich" class="gptg-modal-section"><h3>Email &amp; social</h3>';
			html += '<p id="gptg-contact-enrich-status" class="gptg-contact-status"></p>';
			if (gptgAdmin.contactEnabled && place.websiteUri) {
				html += '<p class="gptg-contact-actions"><button type="button" class="button button-small" id="gptg-contact-retry">' + escapeHtml(gptgAdmin.i18n.retryContact) + '</button></p>';
			}
			html += '<p><label>Email<br/><input type="email" id="gptg-contact-email" class="regular-text" value="' + escapeHtml(contact.email || '') + '" /></label></p>';
			html += '<p><label>Facebook<br/><input type="url" id="gptg-contact-facebook" class="regular-text" value="' + escapeHtml(contact.facebook || '') + '" placeholder="https://facebook.com/..." /></label></p>';
			html += '<p><label>X / Twitter<br/><input type="url" id="gptg-contact-twitter" class="regular-text" value="' + escapeHtml(contact.twitter || '') + '" placeholder="https://twitter.com/..." /></label></p>';
			html += '<p><label>Instagram<br/><input type="url" id="gptg-contact-instagram" class="regular-text" value="' + escapeHtml(contact.instagram || '') + '" placeholder="https://instagram.com/..." /></label></p>';
			html += '<p><label>LinkedIn<br/><input type="url" id="gptg-contact-linkedin" class="regular-text" value="' + escapeHtml(contact.linkedin || '') + '" placeholder="https://linkedin.com/company/..." /></label></p>';
			html += '</div>';

			if (place.gptgHoursText || place.regularOpeningHours) {
				html += '<div class="gptg-modal-section"><h3>Hours</h3><p>' + escapeHtml(place.gptgHoursText || '') + '</p></div>';
			}

			if (place.photos && place.photos.length) {
				html += '<div class="gptg-modal-section"><h3>Photos</h3><div class="gptg-photo-gallery">';
				place.photos.slice(0, 8).forEach(function(photo) {
					var src = photo.gptgPhotoUrl || '';
					if (src) {
						html += '<img src="' + escapeHtml(src) + '" alt="" loading="lazy" />';
					}
				});
				html += '</div></div>';
			}

			if (place.reviews && place.reviews.length) {
				html += '<div class="gptg-modal-section"><h3>Reviews</h3>';
				place.reviews.slice(0, 3).forEach(function(review) {
					var author = review.authorAttribution && review.authorAttribution.displayName ? review.authorAttribution.displayName : 'Guest';
					var text = review.text && review.text.text ? review.text.text : '';
					html += '<blockquote><strong>' + escapeHtml(author) + '</strong> — ' + escapeHtml(text) + '</blockquote>';
				});
				html += '</div>';
			}

			html += '<div class="gptg-modal-section"><h3>Google metadata</h3>';
			html += '<p><code>' + escapeHtml(place.id || '') + '</code></p>';
			html += '</div>';

			html += '<div id="gptg-modal-taxonomies" class="gptg-modal-section"><h3>Categories &amp; Tags</h3>';
			html += '<div id="gptg-modal-taxonomy-chips" class="gptg-modal-taxonomy-chips"></div>';
			html += '<div id="gptg-modal-taxonomy-fields"></div></div>';

			$('#gptg-modal-content').html(html).show();
		}

		function renderModalTaxonomyChipsHtml(place) {
			var tax = place && place.gptgTaxonomy;
			if (!tax) {
				return '';
			}
			var html = '';
			(tax.categories || []).forEach(function(cat) {
				html += '<span class="gptg-tax-chip gptg-tax-chip-cat">' + escapeHtml(cat.name || '') + '</span>';
			});
			(tax.tags || []).forEach(function(tag) {
				html += '<span class="gptg-tax-chip gptg-tax-chip-tag">' + escapeHtml(tag.name || '') + '</span>';
			});
			if (!(tax.category_ids && tax.category_ids.length) && !(tax.categories && tax.categories.length)) {
				html += '<span class="gptg-tax-chip gptg-tax-chip-empty">' + escapeHtml(gptgAdmin.i18n.noCategoriesMatched || 'No categories matched') + '</span>';
			}
			return html;
		}

		function updateModalTaxonomyChips(place) {
			$('#gptg-modal-taxonomy-chips').html(renderModalTaxonomyChipsHtml(place));
		}

		function setupModalTaxonomies(place) {
			modalCategoryIds = place.gptgTaxonomy ? (place.gptgTaxonomy.category_ids || []).slice() : [];
			modalTagIds = place.gptgTaxonomy ? (place.gptgTaxonomy.tag_ids || []).slice() : [];
			modalTaxonomyReviewed = true;
			updateModalTaxonomyChips(place);
			loadModalTaxonomyLists(modalCategoryIds, modalTagIds);
		}

		function loadModalTaxonomyLists(selectedCats, selectedTags) {
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_get_taxonomies',
					nonce: gptgAdmin.nonce,
					post_type: gptgAdmin.importPostType
				},
				success: function(response) {
					if (response.success) {
						renderTaxonomyFields(response.data, selectedCats, selectedTags);
					}
				}
			});
		}

		function updateModalContactStatus(place) {
			var c = getContact(place);
			var status = '';
			if (c.source) {
				status = c.source.charAt(0).toUpperCase() + c.source.slice(1);
				if (c.fetched_at) {
					status += ' — ' + c.fetched_at;
				}
			} else if (place.websiteUri && gptgAdmin.contactEnabled) {
				status = gptgAdmin.contactEnabled ? 'Contact data from search results.' : '';
			} else if (!place.websiteUri) {
				status = gptgAdmin.i18n.contactNoWebsite;
			}
			$('#gptg-contact-enrich-status').text(status);
		}

		function renderTaxonomyFields(lists, selectedCats, selectedTags) {
			var html = '';
			if (lists.categories && lists.categories.length) {
				html += '<p><strong>Categories</strong></p><div class="gptg-tax-checkboxes">';
				lists.categories.forEach(function(term) {
					var checked = selectedCats.indexOf(term.id) !== -1 ? ' checked' : '';
					html += '<label><input type="checkbox" class="gptg-cat-checkbox" value="' + term.id + '"' + checked + ' /> ' + escapeHtml(term.name) + '</label>';
				});
				html += '</div>';
			}
			if (lists.tags && lists.tags.length) {
				html += '<p><strong>Tags</strong></p><div class="gptg-tax-checkboxes">';
				lists.tags.forEach(function(term) {
					var checked = selectedTags.indexOf(term.id) !== -1 ? ' checked' : '';
					html += '<label><input type="checkbox" class="gptg-tag-checkbox" value="' + term.id + '"' + checked + ' /> ' + escapeHtml(term.name) + '</label>';
				});
				html += '</div>';
			}
			if (html) {
				$('#gptg-modal-taxonomy-fields').html(html);
				$('#gptg-modal-taxonomies').show();
			}
		}

		function collectModalTaxonomyIds() {
			var cats = [];
			var tags = [];
			$('.gptg-cat-checkbox:checked').each(function() {
				cats.push(parseInt($(this).val(), 10));
			});
			$('.gptg-tag-checkbox:checked').each(function() {
				tags.push(parseInt($(this).val(), 10));
			});
			if (!cats.length && modalCategoryIds.length) {
				cats = modalCategoryIds.slice();
			}
			if (!tags.length && modalTagIds.length) {
				tags = modalTagIds.slice();
			}
			return { category_ids: cats, tag_ids: tags };
		}

		function importPlace(place, index, taxonomyOverride, callback, fromModal) {
			var tax = taxonomyOverride;
			if (!tax) {
				if (fromModal) {
					tax = collectModalTaxonomyIds();
				} else if (place.gptgTaxonomy) {
					tax = {
						category_ids: (place.gptgTaxonomy.category_ids || []).slice(),
						tag_ids: (place.gptgTaxonomy.tag_ids || []).slice()
					};
				} else {
					tax = { category_ids: [], tag_ids: [] };
				}
			}
			place = applyContactToPlace(place);
			if (index !== null && index !== undefined) {
				setImportStatus(index, gptgAdmin.i18n.importing, '');
			}

			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_import_place',
					nonce: gptgAdmin.nonce,
					post_type: gptgAdmin.importPostType,
					post_status: gptgAdmin.importPostStatus,
					place: JSON.stringify(place),
					gptg_contact: JSON.stringify(place.gptgContact || {}),
					category_ids: JSON.stringify(tax.category_ids || []),
					tag_ids: JSON.stringify(tax.tag_ids || []),
					taxonomy_reviewed: fromModal && modalTaxonomyReviewed ? 1 : 0
				},
				success: function(response) {
					if (response.success) {
						var msg = response.data.message || gptgAdmin.i18n.importSuccess;
						if (index !== null && index !== undefined) {
							var type = response.data.status === 'duplicate' ? 'duplicate' : 'success';
							setImportStatus(index, msg, type);
						}
						if (callback) {
							callback(null, response.data);
						}
					} else {
						var err = response.data && response.data.message ? response.data.message : gptgAdmin.i18n.error;
						if (index !== null && index !== undefined) {
							setImportStatus(index, err, 'error');
						}
						if (callback) {
							callback(err);
						} else {
							alert(err);
						}
					}
				},
				error: function() {
					if (index !== null && index !== undefined) {
						setImportStatus(index, gptgAdmin.i18n.error, 'error');
					}
					if (callback) {
						callback(gptgAdmin.i18n.error);
					} else {
						alert(gptgAdmin.i18n.error);
					}
				}
			});
		}

		function importPlaceById(placeId, index, callback) {
			setImportStatus(index, gptgAdmin.i18n.importing, '');
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_import_places',
					nonce: gptgAdmin.nonce,
					post_type: gptgAdmin.importPostType,
					place_id: placeId
				},
				success: function(response) {
					if (response.success) {
						var type = response.data.status === 'duplicate' ? 'duplicate' : 'success';
						setImportStatus(index, response.data.message || gptgAdmin.i18n.importSuccess, type);
						if (callback) {
							callback(null, response.data);
						}
					} else {
						var err = response.data && response.data.message ? response.data.message : gptgAdmin.i18n.error;
						setImportStatus(index, err, 'error');
						if (callback) {
							callback(err);
						}
					}
				},
				error: function() {
					setImportStatus(index, gptgAdmin.i18n.error, 'error');
					if (callback) {
						callback(gptgAdmin.i18n.error);
					}
				}
			});
		}

		function runBulkImport(indices) {
			if (!indices.length || bulkImportRunning) {
				return;
			}
			bulkImportRunning = true;
			var $btn = $('#gptg-import-selected');
			$btn.prop('disabled', true).text('Importing...');

			var i = 0;
			function next() {
				if (i >= indices.length) {
					bulkImportRunning = false;
					$btn.prop('disabled', false).text('Import Selected to GeoDirectory');
					return;
				}
				var index = indices[i];
				var place = lastSearchPlaces[index];
				var placeId = getPlaceId(place);
				i++;
				if (!placeId) {
					setImportStatus(index, 'Missing place ID', 'error');
					setTimeout(next, 400);
					return;
				}
				importPlace(place, index, null, function() {
					setTimeout(next, 800);
				}, false);
			}
			next();
		}

		$(document).on('click', '.gptg-place-clickable', function(e) {
			if ($(e.target).is('a, input, button')) {
				return;
			}
			var index = parseInt($(this).closest('.gptg-place-item').attr('data-place-index'), 10);
			if (!isNaN(index)) {
				openPlaceModal(index);
			}
		});

		$(document).on('click', '.gptg-row-details', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var index = parseInt($(this).attr('data-place-index'), 10);
			if (!isNaN(index)) {
				openPlaceModal(index);
			}
		});

		$(document).on('click', '.gptg-row-import', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var index = parseInt($(this).attr('data-place-index'), 10);
			var place = lastSearchPlaces[index];
			if (!place) {
				return;
			}
			importPlace(place, index, null, null, false);
		});

		$(document).on('click', '#gptg-contact-retry', function(e) {
			e.preventDefault();
			if (!modalPlace || modalPlaceIndex === null) {
				return;
			}
			applyContactToPlace(modalPlace);
			loadModalContactEnrichment(modalPlace, modalPlaceIndex, true);
		});

		$('.gptg-modal-close, .gptg-modal-close-btn, .gptg-modal-backdrop').on('click', function() {
			closePlaceModal();
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' && $('#gptg-place-modal').is(':visible')) {
				closePlaceModal();
			}
		});

		$('#gptg-modal-import').on('click', function() {
			if (!modalPlace) {
				return;
			}
			var $btn = $(this);
			$btn.prop('disabled', true).text(gptgAdmin.i18n.importing);
			importPlace(modalPlace, modalPlaceIndex, collectModalTaxonomyIds(), function(err, data) {
				$btn.prop('disabled', false).text('Import to GeoDirectory');
				if (!err && data) {
					var links = '';
					if (data.edit_url) {
						links += ' <a href="' + data.edit_url + '" target="_blank">' + gptgAdmin.i18n.editListing + '</a>';
					}
					if (data.view_url) {
						links += ' <a href="' + data.view_url + '" target="_blank">' + gptgAdmin.i18n.viewListing + '</a>';
					}
					$('#gptg-modal-content').prepend('<div class="gptg-message success">' + escapeHtml(data.message) + links + '</div>');
				}
			}, true);
		});

		$('#gptg-modal-save').on('click', function() {
			if (!modalPlace) {
				return;
			}
			var $btn = $(this);
			$btn.prop('disabled', true).text('Saving...');
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_save_places',
					nonce: gptgAdmin.nonce,
					places: JSON.stringify([modalPlace])
				},
				success: function(response) {
					if (response.success) {
						alert(response.data.message);
						$('#gptg-places-count').text(response.data.total);
					} else {
						alert(response.data.message || gptgAdmin.i18n.error);
					}
				},
				complete: function() {
					$btn.prop('disabled', false).text('Save to Export List');
				}
			});
		});

		$('#gptg-import-selected').on('click', function() {
			var indices = [];
			$('.gptg-place-checkbox:checked').each(function() {
				var index = parseInt($(this).attr('data-place-index'), 10);
				if (!isNaN(index)) {
					indices.push(index);
				}
			});
			if (!indices.length) {
				alert('Please select at least one place.');
				return;
			}
			runBulkImport(indices);
		});

		$('#gptg-clear-import-log').on('click', function() {
			if (!confirm('Clear import log?')) {
				return;
			}
			$.ajax({
				url: gptgAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'gptg_clear_import_log',
					nonce: gptgAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						location.reload();
					}
				}
			});
		});
	});
	
})(jQuery);

