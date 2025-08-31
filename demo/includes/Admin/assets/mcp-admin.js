/**
 * MCP Admin JavaScript
 */
jQuery(document).ready(function($) {
	
	// Tab functionality
	window.showTab = function(tabName) {
		// Hide all tabs
		$('.tab-content').hide();
		$('.nav-tab').removeClass('nav-tab-active');
		
		// Show selected tab
		$('#' + tabName + '-tab').show();
		$('a[href="#' + tabName + '"]').addClass('nav-tab-active');
	};
	
	// Server form functions
	window.showAddServerForm = function() {
		$('#server-form').show();
		$('#form-title').text('Add New Server');
		$('#mcp-server-form')[0].reset();
		$('#server-id').val('');
	};

	window.hideServerForm = function() {
		$('#server-form').hide();
	};

	window.toggleAuthFields = function() {
		var authType = $('#auth-type').val();
		$('#bearer-token-row, #api-key-row, #basic-auth-row').hide();
		
		if (authType === 'bearer') {
			$('#bearer-token-row').show();
		} else if (authType === 'api_key') {
			$('#api-key-row').show();
		} else if (authType === 'basic') {
			$('#basic-auth-row').show();
		}
	};

	window.loadServerDetails = function(serverId) {
		if (!serverId) return;
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'get_mcp_server',
				server_id: serverId,
				_ajax_nonce: mcpAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					var server = response.data;
					$('input[name="server_url"]').val(server.url);
					$('input[name="client_id"]').val('wordpress-' + serverId);
				}
			}
		});
	};

	// Test form handler
	$('#mcp-test-form').on('submit', function(e) {
		e.preventDefault();
		
		var $results = $('#mcp-test-results');
		$results.html('<p>Testing connection...</p>');
		
		var serverUrl = $('#mcp-test-form input[name="server_url"]').val();
		var clientId = $('#mcp-test-form input[name="client_id"]').val();
		
		console.log('Debug: Server URL =', serverUrl);
		console.log('Debug: Client ID =', clientId);
		
		if (!serverUrl || !clientId) {
			$results.html('<div class="notice notice-error"><p><strong>Error:</strong> Please fill in both Server URL and Client ID</p></div>');
			return;
		}
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'test_mcp_client',
				server_url: serverUrl,
				client_id: clientId,
				_ajax_nonce: mcpAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					$results.html('<div class="notice notice-success"><p><strong>Success!</strong></p>' + response.data + '</div>');
				} else {
					$results.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + response.data + '</p></div>');
				}
			},
			error: function() {
				$results.html('<div class="notice notice-error"><p>AJAX request failed</p></div>');
			}
		});
	});

	// Server form handler
	$('#mcp-server-form').on('submit', function(e) {
		e.preventDefault();
		
		var formData = {
			action: 'save_mcp_server',
			server_id: $('#server-id').val(),
			server_name: $('#server-name').val(),
			server_url: $('#server-url').val(),
			auth_type: $('#auth-type').val(),
			bearer_token: $('#bearer-token').val(),
			api_key: $('#api-key').val(),
			api_header: $('#api-header').val(),
			username: $('#username').val(),
			password: $('#password').val(),
			timeout: $('#timeout').val(),
			ssl_verify: $('#ssl-verify').is(':checked') ? 1 : 0,
			_ajax_nonce: mcpAdmin.nonce
		};
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: formData,
			success: function(response) {
				if (response.success) {
					location.reload();
				} else {
					alert('Error: ' + response.data);
				}
			},
			error: function() {
				alert('AJAX request failed');
			}
		});
	});

	// Edit server handler
	$(document).on('click', '.edit-server', function() {
		var serverId = $(this).closest('.server-item').data('server-id');
		// Load server data and populate form
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'get_mcp_server',
				server_id: serverId,
				_ajax_nonce: mcpAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					var server = response.data;
					$('#server-id').val(serverId);
					$('#server-name').val(server.name);
					$('#server-url').val(server.url);
					$('#auth-type').val(server.auth.type || 'none');
					toggleAuthFields();
					$('#bearer-token').val(server.auth.token || '');
					$('#api-key').val(server.auth.key || '');
					$('#api-header').val(server.auth.header || 'X-API-Key');
					$('#username').val(server.auth.username || '');
					$('#password').val(server.auth.password || '');
					$('#timeout').val(server.timeout || 30);
					$('#ssl-verify').prop('checked', server.ssl_verify !== false);
					$('#form-title').text('Edit Server: ' + server.name);
					$('#server-form').show();
				}
			}
		});
	});

	// Delete server handler
	$(document).on('click', '.delete-server', function() {
		if (confirm('Are you sure you want to delete this server?')) {
			var serverId = $(this).closest('.server-item').data('server-id');
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'delete_mcp_server',
					server_id: serverId,
					_ajax_nonce: mcpAdmin.nonce
				},
				success: function(response) {
					if (response.success) {
						location.reload();
					} else {
						alert('Error: ' + response.data);
					}
				}
			});
		}
	});

	// Server status checking
	window.checkServerStatus = function(serverId) {
		var $statusIndicator = $('#status-' + serverId);
		var $capabilities = $('#capabilities-' + serverId);
		var $serverItem = $statusIndicator.closest('.server-item');
		
		// Clear any previous error messages
		$serverItem.find('.connection-error').remove();
		
		$statusIndicator.removeClass('status-connected status-disconnected status-unknown')
			.addClass('status-unknown').text('Checking...');
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'check_server_status',
				server_id: serverId,
				_ajax_nonce: mcpAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					var data = response.data;
					
					// Update status
					$statusIndicator.removeClass('status-unknown')
						.addClass('status-connected').text('Connected');
					
					// Show capabilities section
					$capabilities.show();
					
					// Update tools
					var toolsHtml = '';
					if (data.tools && data.tools.length > 0) {
						data.tools.forEach(function(tool) {
							toolsHtml += '<div class="capability-item">';
							toolsHtml += '<strong>' + escapeHtml(tool.name) + '</strong>';
							if (tool.description) {
								toolsHtml += '<div class="capability-desc">' + escapeHtml(tool.description) + '</div>';
							}
							toolsHtml += '</div>';
						});
					} else {
						toolsHtml = '<em>No tools available</em>';
					}
					$('#tools-' + serverId).html(toolsHtml);
					
					// Update resources
					var resourcesHtml = '';
					if (data.resources && data.resources.length > 0) {
						data.resources.forEach(function(resource) {
							resourcesHtml += '<div class="capability-item">';
							resourcesHtml += '<strong>' + escapeHtml(resource.uri) + '</strong>';
							if (resource.description) {
								resourcesHtml += '<div class="capability-desc">' + escapeHtml(resource.description) + '</div>';
							}
							resourcesHtml += '</div>';
						});
					} else {
						resourcesHtml = '<em>No resources available</em>';
					}
					$('#resources-' + serverId).html(resourcesHtml);
					
					// Update prompts
					var promptsHtml = '';
					if (data.prompts && data.prompts.length > 0) {
						data.prompts.forEach(function(prompt) {
							promptsHtml += '<div class="capability-item">';
							promptsHtml += '<strong>' + escapeHtml(prompt.name) + '</strong>';
							if (prompt.description) {
								promptsHtml += '<div class="capability-desc">' + escapeHtml(prompt.description) + '</div>';
							}
							promptsHtml += '</div>';
						});
					} else {
						promptsHtml = '<em>No prompts available</em>';
					}
					$('#prompts-' + serverId).html(promptsHtml);
					
				} else {
					$statusIndicator.removeClass('status-unknown')
						.addClass('status-disconnected').text('Failed');
					$capabilities.hide();
					
					// Show detailed error message (don't escape HTML for debug output)
					var $errorMsg = $('<div class="connection-error" style="margin-top: 10px; padding: 10px; background: #fef7f0; border-left: 4px solid #d63638; color: #d63638; font-size: 13px;"><strong>Connection Failed:</strong><br>' + response.data + '</div>');
					$capabilities.after($errorMsg);
					
					// Remove error after 10 seconds
					setTimeout(function() {
						$errorMsg.fadeOut();
					}, 10000);
				}
			},
			error: function(xhr, status, error) {
				$statusIndicator.removeClass('status-unknown')
					.addClass('status-disconnected').text('Error');
				$capabilities.hide();
				
				// Show AJAX error message
				var errorMessage = 'Network request failed';
				if (status === 'timeout') {
					errorMessage = 'Request timeout - server took too long to respond';
				} else if (status === 'error') {
					errorMessage = 'Network error - check your internet connection';
				} else if (status === 'parsererror') {
					errorMessage = 'Server returned invalid data';
				}
				
				var $errorMsg = $('<div class="connection-error" style="margin-top: 10px; padding: 10px; background: #fef7f0; border-left: 4px solid #d63638; color: #d63638; font-size: 13px;"><strong>AJAX Error:</strong> ' + errorMessage + '</div>');
				$capabilities.after($errorMsg);
				
				// Remove error after 10 seconds
				setTimeout(function() {
					$errorMsg.fadeOut();
				}, 10000);
			}
		});
	};

	// Helper function to escape HTML
	function escapeHtml(unsafe) {
		return unsafe
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;")
			.replace(/'/g, "&#039;");
	}

	// Abilities export handler
	$('#export-abilities-form').on('submit', function(e) {
		e.preventDefault();
		
		var $results = $('#export-results');
		$results.html('<p>Exporting abilities...</p>');
		
		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: {
				action: 'export_abilities_mcp',
				_ajax_nonce: mcpAdmin.nonce
			},
			success: function(response) {
				if (response.success) {
					$results.html('<div class="notice notice-success"><p><strong>Success!</strong></p>' + response.data + '</div>');
				} else {
					$results.html('<div class="notice notice-error"><p><strong>Error:</strong> ' + response.data + '</p></div>');
				}
			},
			error: function() {
				$results.html('<div class="notice notice-error"><p>AJAX request failed</p></div>');
			}
		});
	});
});