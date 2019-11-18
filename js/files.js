(function() {

	_.extend(OC.Files.Client, {
		PROPERTY_FILES_LOCK:	'{' + OC.Files.Client.NS_NEXTCLOUD + '}lock'
	})


	var FilesPlugin = {
		attach: function(fileList) {
			var self = this

			var oldGetWebdavProperties = fileList._getWebdavProperties
			fileList._getWebdavProperties = function() {
				var props = oldGetWebdavProperties.apply(this, arguments)
				props.push(OC.Files.Client.PROPERTY_FILES_LOCK)
				return props
			}

			fileList.filesClient.addFileInfoParser(function(response) {
				var data = {}
				var props = response.propStat[0].properties
				var isLocked = props[OC.Files.Client.PROPERTY_FILES_LOCK]
				if (!_.isUndefined(isLocked) && isLocked !== '') {
					data.locked = isLocked === '1'
				}
				return data
			})

			var oldCreateRow = fileList._createRow
			fileList._createRow = function(fileData) {
				var $tr = oldCreateRow.apply(this, arguments)
				if (fileData.locked) {
					$tr.attr('data-locked', fileData.locked)
				}
				return $tr
			}


			fileList.fileActions.registerAction({
				name: 'Locking',
				displayName: function(context) {
					console.log(context.$file.data('locked'))
					if (context && context.$file) {
						var locked = context.$file.data('locked')
						if (locked) {
							return t('files_lock', 'File is locked')
						}
					}
					return t('files_lock', 'File is not locked')
				},
				mime: 'all',
				order: -140,
				iconClass: 'icon-security',
				permissions: OC.PERMISSION_READ,
				actionHandler: function(fileName, context) {
					console.log('handle locking')
				}
			})

			fileList.fileActions.registerAction({
				name: 'LockingInline',
				render: function(actionSpec, isDefault, context) {
					var $file = context.$file
					var locked = context.$file.data('locked')
					var $actionLink = $('<span/>')
					if (locked) {
						$actionLink.text('Locked')
					} else {
						$actionLink.text('Not locked')

					}
					context.$file.find('a.name>span.fileactions').append($actionLink)
					return $actionLink
				},
				mime: 'all',
				order: -140,
				type: OCA.Files.FileActions.TYPE_INLINE,
				permissions: OC.PERMISSION_READ,
				actionHandler: function(fileName, context) {
					console.log('handle locking')
				}
			})

		}
	};

	OC.Plugins.register('OCA.Files.FileList', FilesPlugin)

})();
