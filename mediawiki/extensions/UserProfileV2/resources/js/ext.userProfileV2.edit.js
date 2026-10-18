/**
 * Shows 2 modal windows — 1 to edit the user information, and 1 to edit the avatar of a user
 * Copied from https://www.mediawiki.org/wiki/OOUI/Windows/Process_Dialogs and adapted to suit
 */
$(document).ready(function () {
	(function () {
		function ProcessDialog(config) {
			ProcessDialog.super.call(this, config);
		}

		OO.inheritClass(ProcessDialog, OO.ui.ProcessDialog);
		ProcessDialog.static.name = 'userProfileV2Edit';
		ProcessDialog.static.title = mw.message('userprofilev2-edit-profile').text(); //'Edit Profile';

		ProcessDialog.static.actions = [
			{
				action: 'continue',
				modes: 'edit',
				label: mw.message('savechanges').text(),
				flags: ['primary', 'progressive']
			},
			{
				action: 'help',
				modes: 'edit',
				label: mw.message('help').text()
			},
			{
				modes: 'edit',
				label: mw.message('cancel').text(),
				flags: ['safe', 'close']
			},
			{
				action: 'back',
				modes: 'help',
				label: mw.message('userprofilev2-edit-back'),
				flags: ['safe', 'back']
			}
		];

		/**
		 * Set up the first modal window which edits the user information
		 */
		ProcessDialog.prototype.initialize = function () {
			ProcessDialog.super.prototype.initialize.apply(this, arguments);

			this.panel1 = new OO.ui.PanelLayout({padded: true, expanded: false});
			this.addFieldsToPanel(this.panel1);
			this.panel2 = new OO.ui.PanelLayout({padded: true, expanded: false});
			this.panel2.$element.append( $( '<p>' ).text( mw.message('userprofilev2-helptext').text() ) );
			this.stackLayout = new OO.ui.StackLayout({
				items: [this.panel1, this.panel2]
			});
			this.$body.append(this.stackLayout.$element);

			// get the API data and set it
			const dialog = this;
			getUserData().then(function (userData) {
				dialog.aboutMe.setValue(userData.query[0]['profile-aboutme'] || '');
				dialog.discordLink.setValue(userData.query[0]['profile-discord'] || '');
				dialog.twitterLink.setValue(userData.query[0]['profile-twitter'] || '');
				dialog.mastodonLink.setValue(userData.query[0]['profile-mastodon'] || '');
				dialog.showGlobalGroups.setSelected(userData.query[0]['profile-show-globalgroups'] === "1");
				dialog.showGlobalEditCount.setSelected(userData.query[0]['profile-show-globaledits'] === "1");
				dialog.userAvatar = userData.query[0]['profile-avatar'];
			}).catch(function (error) {
				console.error('Could not set the data for this user:', error);
			});
		};

		ProcessDialog.prototype.getSetupProcess = function (data) {
			return ProcessDialog.super.prototype.getSetupProcess.call(this, data)
				.next(function () {
					this.actions.setMode('edit');
				}, this);
		};

		/**
		 * Handle all of the actions that can be taken with this modal
		 */
		ProcessDialog.prototype.getActionProcess = function (action) {
			if (action === 'help') {
				this.actions.setMode('help');
				this.stackLayout.setItem(this.panel2);
			} else if (action === 'back') {
				this.actions.setMode('edit');
				this.stackLayout.setItem(this.panel1);
			} else if (action === 'continue') {
				var dialog = this;
				return new OO.ui.Process(function () {
					// Collect form data
					var formData = {
						'profile-aboutme': dialog.aboutMe.getValue(),
						'profile-discord': dialog.discordLink.getValue(),
						'profile-twitter': dialog.twitterLink.getValue(),
						'profile-mastodon': dialog.mastodonLink.getValue(),
						'profile-show-globalgroups': dialog.showGlobalGroups.isSelected() ? '1' : '',
						'profile-show-globaledits': dialog.showGlobalEditCount.isSelected() ? '1' : ''
					};

					// Submit data to API
					return submitUserData(formData).then(function () {
						dialog.close({action: action});
					}).catch(function (error) {
						console.error('Failed to save profile:', error);
					});
				});
			}
			return ProcessDialog.super.prototype.getActionProcess.call(this, action);
		};

		ProcessDialog.prototype.getBodyHeight = function () {
			return this.panel1.$element.outerHeight(true);
		};

		ProcessDialog.prototype.addFieldsToPanel = function (panel) {
			var fieldset = this.getEditFields();
			panel.$element.append(fieldset.$element);
		};

		/**
		 * Construct our edit fields
		 * @returns {*}
		 */
		ProcessDialog.prototype.getEditFields = function () {
			this.aboutMe = new OO.ui.MultilineTextInputWidget({
				placeholder: mw.message('userprofilev2-about-me') //'About Me'
			});
			this.discordLink = new OO.ui.TextInputWidget({
				placeholder: mw.message('userprofilev2-discord-placeholder').text()
			});
			this.twitterLink = new OO.ui.TextInputWidget({
				placeholder: mw.message('userprofilev2-twitter-placeholder').text()
			});
			this.mastodonLink = new OO.ui.TextInputWidget({
				placeholder: mw.message('userprofilev2-mastodon-placeholder').text()
			});
			this.showGlobalGroups = new OO.ui.CheckboxInputWidget({
				selected: false
			});
			this.showGlobalEditCount = new OO.ui.CheckboxInputWidget({
				selected: false
			});

			var fieldset = new OO.ui.FieldsetLayout({
				label: mw.message('userprofilev2-edit-profile'), //'Edit Your Profile',
				classes: ['container']
			});

			fieldset.addItems([
				new OO.ui.FieldLayout(this.aboutMe, {
					label: mw.message('userprofilev2-about-me'), //'About Me',
					align: 'top',
					help: mw.message('userprofilev2-about-me-help').text(),
					helpInline: true
				}),
				new OO.ui.FieldLayout(this.discordLink, {
					label: mw.message('userprofilev2-discord').text(),
					align: 'top',
					help: mw.message('userprofilev2-discord-help').text()
				}),
				new OO.ui.FieldLayout(this.twitterLink, {
					label: mw.message('userprofilev2-twitter').text(),
					align: 'top',
					help: mw.message('userprofilev2-twitter-help').text()
				}),
				new OO.ui.FieldLayout(this.mastodonLink, {
					label: mw.message('userprofilev2-mastodon').text(),
					align: 'top',
					help: mw.message('userprofilev2-mastodon-help').text()
				}),
				new OO.ui.FieldLayout(this.showGlobalGroups, {
					label: mw.message('userprofilev2-showglobalgroups').text(),
					align: 'inline',
					help: mw.message('userprofilev2-showglobalgroupshelp').text()
				}),
				new OO.ui.FieldLayout(this.showGlobalEditCount, {
					label: mw.message('userprofilev2-showglobaleditcount').text(),
					align: 'inline',
					help: mw.message('userprofilev2-showglobaleditcounthelp').text()
				})
			]);

			return fieldset;
		};

		/**
		 * The avatar url will be returned in the initial API call made when the first dialog is initialized
		 * this is a hacky function to pass it to the avatar dialog to avoid making a second API call.
		 * @param config
		 * @constructor
		 */
		function UserAvatarDialog(config) {
			UserAvatarDialog.super.call(this, config);
			this.getUserAvatar = config.getUserAvatar || function () {
				return null;
			};
		}

		OO.inheritClass(UserAvatarDialog, OO.ui.ProcessDialog);
		UserAvatarDialog.static.name = 'Edit Avatar';
		UserAvatarDialog.static.title = mw.message('userprofilev2-editavatar').text();

		UserAvatarDialog.static.actions = [
			{action: 'save', label: mw.message('savechanges').text(), flags: ['primary', 'progressive']},
			{action: 'delete', label: mw.message('userprofilev2-deleteavatar').text(), flags: ['primary', 'destructive']},
			{label: mw.message('cancel').text(), flags: ['safe', 'close']}
		];

		UserAvatarDialog.prototype.initialize = function () {
			UserAvatarDialog.super.prototype.initialize.apply(this, arguments);

			this.horizontalLayout = new OO.ui.HorizontalLayout({
				classes: ['userprofilev2-avatar-dialog']
			});

			this.leftPanel = new OO.ui.PanelLayout({
				padded: true,
				expanded: false,
				classes: ['left-panel']
			});

			this.leftPanel.$element.append('<div id="current-avatar"></div>');

			this.rightPanel = new OO.ui.PanelLayout({
				padded: true,
				expanded: false,
				classes: ['right-panel']
			});

			const fileInputWidget = new OO.ui.SelectFileInputWidget({
				accept: [
					'image/png',
					'image/jpeg'
				],
				button: {
					flags: [
						'progressive'
					],
					icon: 'upload',
					label: mw.message('userprofilev2-selectavatar').text()
				},
				showDropTarget: true
			});

			this.rightPanel.$element.append(fileInputWidget.$element);

			this.horizontalLayout.addItems([this.leftPanel, this.rightPanel]);

			this.$body.append(this.horizontalLayout.$element);

			fileInputWidget.on('change', function () {
				let avatar = fileInputWidget.currentFiles[0];
				if (avatar) {
					let reader = new FileReader();
					reader.onload = function (e) {
						this.updateAvatarDisplay(e.target.result);
					}.bind(this);
					reader.readAsDataURL(avatar);

					this.selectedFile = avatar;
				}
			}.bind(this));
		};

		UserAvatarDialog.prototype.getSetupProcess = function (data) {
			return UserAvatarDialog.super.prototype.getSetupProcess.call(this, data)
				.next(function () {
					this.userAvatar = this.getUserAvatar();
					this.updateAvatarDisplay();
				}, this);
		};

		UserAvatarDialog.prototype.updateAvatarDisplay = function (avatarSrc) {
			var avatarElement = this.leftPanel.$element.find('#current-avatar');
			if (avatarSrc) {
				avatarElement.html('<img src="' + avatarSrc + '" alt="Current Avatar">');
			} else if (this.userAvatar) {
				avatarElement.html('<img src="' + this.userAvatar + '" alt="Current Avatar">');
			} else {
				avatarElement.html('<p>No avatar selected</p>');
			}
		};

		UserAvatarDialog.prototype.getActionProcess = function (action) {
			var dialog = this;
			if (action === 'save') {
				return new OO.ui.Process(function () {
					if (dialog.selectedFile) {
						return changeAvatar(dialog.selectedFile).then(function (response) {
							const avatarUrl = response.userprofilev2uploadavatar.url;
							$('.profile-avatar-image').attr('src', avatarUrl);
							dialog.close({action: action});
							mw.notify(mw.message('userprofilev2-avatarchanged'));
						}).catch(function (error) {
							dialog.showErrorMessage(error);
						});
					} else {
						dialog.close({action: action});
					}
				});
			} else if (action === 'delete') {
				return new OO.ui.Process(function () {
					return removeAvatar().then(function (response) {
						dialog.close({action: action});
						mw.notify(mw.message('userprofilev2-avatardeleted'));
					}).catch(function (error) {
						dialog.showErrorMessage(error);
					});
				});
			}
			return UserAvatarDialog.super.prototype.getActionProcess.call(this, action);
		};

		UserAvatarDialog.prototype.showErrorMessage = function (error) {
			const topPanel = new OO.ui.PanelLayout({
				padded: true,
				expanded: false,
				classes: ['userprofilev2-avatar-error-message']
			});
			let errorMessage = mw.message(error);
			const messageBox = new OO.ui.MessageWidget({
				type: 'error',
				label: errorMessage.key.message // get the message from i18n
			});

			topPanel.$element.append(messageBox.$element);

			this.$body.prepend(topPanel.$element);
		};

		// Create a single WindowManager for both dialogs
		var windowManager = new OO.ui.WindowManager();
		$(document.body).append(windowManager.$element);

		// Create instances of both dialogs
		var processDialog = new ProcessDialog({
			size: 'medium'
		});
		var avatarDialog = new UserAvatarDialog({
			size: 'large',
			getUserAvatar: function () {
				return processDialog.userAvatar;
			}
		});

		// Add both windows to the WindowManager
		windowManager.addWindows([processDialog, avatarDialog]);

		// Event handlers for opening each dialog
		$('#userProfileV2-edit').on('click', function () {
			windowManager.openWindow(processDialog);
		});

		$('.profile-avatar-edit-action').on('click', function () {
			windowManager.openWindow(avatarDialog);
		});

	})();

	/**
	 * Get our data from the API.
	 * @returns {*|Promise<any>}
	 */
	function getUserData() {
		const api = new mw.Api();
		return api.get({
			action: 'query',
			format: 'json',
			list: 'queryuserprofilev2',
			user_name: mw.config.get('wgRelevantUserName')
		}).then(function (userData) {
			return userData;
		}).catch(function (error) {
			console.error('API request failed:', error);
			throw error;
		});
	}

	/**
	 * Send our data to the API.
	 * @param formData
	 * @returns {*}
	 */
	function submitUserData(formData) {
		const api = new mw.Api();

		const profileData = Object.entries(formData)
			.map(([key, value]) => `${key}=${encodeURIComponent(value)}`)
			.join('|');

		return api.postWithToken('csrf', {
			action: 'setuserprofilev2',
			format: 'json',
			user_name: mw.config.get('wgRelevantUserName'), // the username of the profile we're viewing
			profile_data: profileData
		}).then(function (response) {
			if (response.error) {
				throw new Error(response.error.info || 'Unknown error occurred');
			}
			return response;
		});
	}

	/**
	 * Removes an avatar for the user profile you're viewing
	 * @returns {*}
	 */
	function removeAvatar() {
		const api = new mw.Api();

		return api.postWithToken('csrf', {
			action: 'userprofilev2deleteavatar',
			format: 'json',
			username: mw.config.get('wgRelevantUserName'), // the username of the profile we're viewing
		}).then(function (response) {
			if (response.error) {
				throw new Error(response.error.info || 'Unknown error occurred');
			}
			return response;
		});
	}

	/**
	 * Actually upload our avatar, this is really fucked because I wanted to use mw.api.postWithToken
	 * but that turned out to be a nightmare so this will have to do, I guess.
	 * Someone improve this? (2 hours debugging spent trying to get mw.api.postWithToken to work, increase this if you try)
	 * @param avatar
	 * @returns {*}
	 */
	function changeAvatar(avatar) {

		const fileToUpload = avatar;
		let formData = new FormData();
		formData.append("action", "userprofilev2uploadavatar");
		formData.append("format", "json");
		formData.append("filename", "xyz"); // doesn't matter what we pass here, because it will be overwritten by the API, but the API won't accept the request without a filename.
		formData.append("token", mw.user.tokens.get('csrfToken'));
		formData.append("file", fileToUpload);
		formData.append("username", mw.config.get('wgRelevantUserName'));

		return $.ajax({
			url: mw.util.wikiScript('api'),
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false
		}).then(function (response) {
			if (response.error) {
				throw new Error(response.error.info || 'Unknown error occurred');
			}
			return response;
		});
	}
});
