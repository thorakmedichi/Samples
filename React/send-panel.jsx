var SendPanel = React.createClass({
	getDefaultProps: function() {
		var tabs = ['sms', 'email', 'note', 'shipment'];
		if (window.userRole === 'super_admin') {
			tabs.push('payment')
		}

		return {
			title: '',
			tabs: tabs
		};
	},

	getInitialState: function() {
		return {
			selectedTab: 'sms',
			messageText: '',
			content: [],
			response: {
				type: '',
				message: ''
			}
		};
	},

	componentDidMount: function (){
		$('textarea').expanding();
	},

	componentDidUpdate: function(){
		$('.help').popover();
	},

	onTabChange: function(type){
		this.setState({
			selectedTab: type
		});
	},

	handleMessageChange: function(event){
		this.setState({
			messageText: event.target.value
		});
	},

	handleSubmit: function(url, data) {

		$.ajax({
			type: 'POST',
			url: url,
			dataType: 'json',
			contentType: false,
			processData: false,
			data: data,
			cache: false,

			beforeSend: function(){
				$(".alert").fadeTo(0, 1);
				this.setState({
					disabled: true,
					response: {
						type: 'info',
						message: 'Sending...'
					}
				});
			}.bind(this),

			success: function(data) {
				this.setState({
					disabled: false,
					messageText: '',
					response: {
						type: (data.success) ? 'success' : 'error',
						message: data.message
					}
				});

				// Fade the alert box out if it was a success message
				if (data.success){
					window.setTimeout(function() {
						$(".send-alert").fadeTo(500, 0).slideUp(500);
					}, 5000);
				}
			}.bind(this),
			
			error: function(xhr, status, err) {
				console.error(this.props.url, status, err.toString());
				this.setState({
					disabled: false,
					response: {
						type: 'error',
						message: 'There was an issue sending your message. Please contact the Administrator!'
					}
				});
			}.bind(this)
		});
	},

	render: function() {
		// Define a list of swapable components we can dynamically insert into the component
		var allComponents = {
			sms: <SmsForm customerId={window.customerId} handleSubmit={this.handleSubmit} messageText={this.state.messageText} onMessageChange={this.handleMessageChange} />,
			email: <EmailForm customerId={window.customerId} handleSubmit={this.handleSubmit} messageText={this.state.messageText} onMessageChange={this.handleMessageChange} />,
			note: <NoteForm customerId={window.customerId} handleSubmit={this.handleSubmit} messageText={this.state.messageText} onMessageChange={this.handleMessageChange} />,
			shipment: <ShipmentForm customerId={window.customerId} handleSubmit={this.handleSubmit} messageText={this.state.messageText} onMessageChange={this.handleMessageChange} />,
			payment: <PaymentForm customerId={window.customerId} handleSubmit={this.handleSubmit} messageText={this.state.messageText} onMessageChange={this.handleMessageChange} />
		};

		return (
			<div className="clearfix">
				<div className="col-md-8">
					<div className="panel panel-primary">
						<PanelHeading tabs={this.props.tabs} selectedTab={this.state.selectedTab} onTabChange={this.onTabChange} title={this.props.title} />
						<AlertPanel response={this.state.response} />
						<PanelContent tabs={this.props.tabs} selectedTab={this.state.selectedTab} >
							{allComponents[this.state.selectedTab]}
						</PanelContent>
					</div>
				</div>

				<div className="col-md-4">
					<div className="panel panel-default">
						<div className="panel-heading">
							<h3 className="panel-title">Tokens</h3>
						</div>

						<div className="panel-body">
							<p>The following tokens can be used to place dynamic information regarding the customer into the email message</p>
							<ul className="tokens">
								<li>{'{name}'}</li>
								<li>{'{phone}'}</li>
								<li>{'{mobile_phone}'}</li>
								<li>{'{address}'}</li>
								<li>{'{city}'}</li>
								<li>{'{state}'}</li>
								<li>{'{country}'}</li>
								<li>{'{brand}'}</li>
								<li>{'{payment_method}'}</li>
								<li>{'{payment_amount}'}</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		);
	}
});

ReactDOM.render(
	<SendPanel />,
	document.getElementById('sendPanel')
);