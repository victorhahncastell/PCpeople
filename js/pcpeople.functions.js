$(document).ready(function(){
	// http://plugins.learningjquery.com/expander/index.html#options
	$('div.PCByBox').expander({
		slicePoint: 200, 
		expandText: gdn.definition('ExpandPCList'), 
		userCollapse: false,
		userCollapseText: gdn.definition('CollapsePCList')
	});
	$('div.PCByBox span.details > a:last').addClass('Last');
	
	$('span.PCtag > a, span.UnPCtag > a').live('click', function(){
		var box, url = this.href, parent = $(this).parent()
		var item = $(this).parents('ul.MessageList > li'); // TODO: add ul.DataList to collection
		$(this).after('<span class="TinyProgress">&#160;</span>');

		$.ajax({
			type: "POST",
			url: url,
			data: 'DeliveryType=DATA&DeliveryMethod=JSON',
			dataType: 'json',
			error: function(XMLHttpRequest, textStatus, errorThrown) {
				$.popup({}, XMLHttpRequest.responseText);
			},
			success: function(Data) {
				parent.fadeOut('fast');
				box = item.find('div.PCByBox').first();
				if (box.length == 0) { // Nobody say thanks for this message, create an empty box and insert it after message (AfterCommentBody event)
					box = $('<div>', {'class':'PCByBox'});
					item.find('div.Message').after(box);
				}
				box.html(Data.NewPCByBox);
				if (typeof $.fn.effect == 'function') box.effect("highlight", {}, "slow");
			},
			complete: function(){
				$('.TinyProgress', item).remove();
			}
		});
		return false;
	});
	
});



