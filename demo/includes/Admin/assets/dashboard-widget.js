/**
 * MCP Dashboard Widget JavaScript
 */

(function($) {
	'use strict';

	/**
	 * Refresh dashboard widget data
	 */
	window.mcpDashboardRefresh = function() {
		var $widget = $('#mcp_demo_overview');
		var $button = $('.mcp-refresh');
		var $widgetContent = $widget.find('#mcp-widget');

		// Show loading state
		$button.prop('disabled', true).text('Refreshing...');
		$widgetContent.addClass('loading');

		// Add loading spinner to button
		$button.prepend('<span class="mcp-loading-spinner"></span>');

		$.ajax({
			url: mcpDashboard.ajaxurl,
			type: 'POST',
			data: {
				action: 'mcp_dashboard_refresh',
				nonce: mcpDashboard.nonce
			},
			success: function(response) {
				if (response.success && response.data) {
					updateWidgetContent(response.data);
					showNotification('MCP status refreshed', 'success');
				} else {
					showNotification('Failed to refresh MCP status', 'error');
				}
			},
			error: function(xhr, status, error) {
				console.error('MCP refresh failed:', error);
				showNotification('Network error while refreshing', 'error');
			},
			complete: function() {
				// Remove loading state
				$button.prop('disabled', false).text('Refresh');
				$button.find('.mcp-loading-spinner').remove();
				$widgetContent.removeClass('loading');
			}
		});
	};

	/**
	 * Update widget content with fresh MCP data
	 */
	function updateWidgetContent(data) {
		var $widget = $('#mcp_demo_overview .inside #mcp-widget');

		// Update servers section
		if (data.servers !== undefined) {
			var $serversBlock = $widget.find('#mcp-servers');
			var $serversList = $serversBlock.find('ul');
			
			if (Object.keys(data.servers).length > 0) {
				if (!$serversList.length) {
					$serversBlock.find('p').remove();
					$serversBlock.append('<ul></ul>');
					$serversList = $serversBlock.find('ul');
				}
				
				$serversList.empty();
				$.each(data.servers, function(serverId, server) {
					var $item = $('<li>' +
						'<strong>' + escapeHtml(server.name) + '</strong> ' +
						'<span class="mcp-server-id">(' + escapeHtml(serverId) + ')</span><br>' +
						'<span class="mcp-stats">' +
						'Tools: ' + (server.tools_count || 0) + ', ' +
						'Resources: ' + (server.resources_count || 0) + ', ' +
						'Prompts: ' + (server.prompts_count || 0) +
						'</span>' +
						'</li>');
					$serversList.append($item);
				});
			} else {
				$serversList.remove();
				if (!$serversBlock.find('p').length) {
					$serversBlock.append('<p>No MCP servers are currently configured.</p>');
				}
			}
		}

		// Update clients section
		if (data.clients !== undefined) {
			var $clientsBlock = $widget.find('#mcp-clients');
			var $clientsList = $clientsBlock.find('ul');
			
			if (Object.keys(data.clients).length > 0) {
				if (!$clientsList.length) {
					$clientsBlock.find('p').remove();
					$clientsBlock.append('<ul></ul>');
					$clientsList = $clientsBlock.find('ul');
				}
				
				$clientsList.empty();
				$.each(data.clients, function(clientId, client) {
					var statusClass = client.connected ? 'mcp-connected' : 'mcp-disconnected';
					var statusText = client.connected ? '[Connected]' : '[Disconnected]';
					
					var $item = $('<li>' +
						'<strong>' + escapeHtml(clientId) + '</strong> ' +
						'<span class="' + statusClass + '">' + statusText + '</span><br>' +
						'<code>' + escapeHtml(client.server_url || '') + '</code>' +
						'</li>');
					$clientsList.append($item);
				});
			} else {
				$clientsList.remove();
				if (!$clientsBlock.find('p').length) {
					$clientsBlock.append('<p>No MCP clients are currently connected.</p>');
				}
			}
		}
	}


	/**
	 * Show notification message
	 */
	function showNotification(message, type) {
		// Create notification element
		var $notification = $('<div class="mcp-notification mcp-notification-' + type + '">' +
			message + 
			'<button type="button" class="mcp-notification-close">&times;</button>' +
			'</div>');

		// Add notification styles if not already present
		if (!$('#mcp-notification-styles').length) {
			$('<style id="mcp-notification-styles">' +
				'.mcp-notification { position: fixed; top: 32px; right: 20px; z-index: 999999; ' +
				'padding: 12px 16px; border-radius: 4px; font-size: 13px; font-weight: 500; ' +
				'box-shadow: 0 2px 8px rgba(0,0,0,0.15); max-width: 300px; } ' +
				'.mcp-notification-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; } ' +
				'.mcp-notification-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; } ' +
				'.mcp-notification-close { background: none; border: none; font-size: 16px; ' +
				'cursor: pointer; float: right; margin-left: 10px; padding: 0; line-height: 1; } ' +
				'.mcp-notification-close:hover { opacity: 0.7; } ' +
				'</style>').appendTo('head');
		}

		// Add to page and auto-remove
		$('body').append($notification);
		
		// Handle close button
		$notification.find('.mcp-notification-close').on('click', function() {
			$notification.fadeOut(300, function() { $(this).remove(); });
		});

		// Auto-remove after 4 seconds
		setTimeout(function() {
			if ($notification.is(':visible')) {
				$notification.fadeOut(300, function() { $(this).remove(); });
			}
		}, 4000);
	}

	/**
	 * Truncate text to specified word count
	 */
	function truncateText(text, wordCount) {
		var words = text.split(' ');
		if (words.length <= wordCount) {
			return text;
		}
		return words.slice(0, wordCount).join(' ') + '...';
	}

	/**
	 * Escape HTML to prevent XSS
	 */
	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Initialize when DOM is ready
	$(document).ready(function() {
		// Auto-refresh every 5 minutes
		setInterval(function() {
			if ($('#mcp_demo_overview').length && document.hasFocus()) {
				mcpDashboardRefresh();
			}
		}, 300000); // 5 minutes

		// Add keyboard shortcut for refresh (Ctrl/Cmd + Shift + R when widget is visible)
		$(document).on('keydown', function(e) {
			if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.which === 82) { // Ctrl/Cmd + Shift + R
				if ($('#mcp_demo_overview').is(':visible')) {
					e.preventDefault();
					mcpDashboardRefresh();
				}
			}
		});

		console.log('MCP Dashboard Widget initialized');
	});

})(jQuery);