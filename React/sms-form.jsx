var SmsForm = React.createClass({
	getInitialState: function() {
		return {
			charsLeft: 160,
			response: {
				type: '',
				message: ''
			}
		};
	},

	componentDidUpdate: function(){
		$('.message-group').popover();
	},

	handleKeyUp: function(event) {
		var input = event.target.value;
		var charsLeft = 159 - input.length;
		this.setState({
			charsLeft: charsLeft
		});

		$('.message-group').popover('show');
		if (charsLeft < 0){
			this.setState({
				response: {
					type: 'error',
					message: 'You have exceeded the max characters allowed for an SMS.'
				}
			});
		} else {
			$('.message-group').popover('hide');
			this.setState({
				response: {
					type: '',
					message: ''
				}
			});
		}
	},

	handleSubmit: function(event) {
		event.preventDefault();

		var url = '/messages/sms/send';

		// Create a formdata object and add the files
		var data = new FormData();
		data.append("to", [this.props.customerId]);
		data.append("message", this.refs.newSmsForm.message.value);

		this.props.handleSubmit(url, data);
	},

	render: function() {
		return (
			<form className="newSmsForm" onSubmit={this.handleSubmit} ref="newSmsForm">
				<div className="form-group error">
					<div className="form-group message-group"
						data-toggle="popover" 
						data-trigger="manual"
						data-placement="right" 
						data-original-title={this.state.response.type} 
						data-content={this.state.response.message}>

						<label>Message</label>
						<BuildTextarea name="message" 
							placeholder="Message" 
							messageText={this.props.messageText} 
							onMessageChange={this.props.onMessageChange} 
							onKeyUp={this.handleKeyUp} 
							disabled={this.state.disabled} 
							isRequired={true} />
						<div>Characters left: <span id="count">{this.state.charsLeft}</span></div>
					</div>
				</div>
				<button type="submit" className="btn btn-md btn-primary btn-labeled fa fa-send pull-right" disabled={this.state.disabled}>Send</button>
			</form>
		)
	},

});
window.SmsForm = SmsForm;