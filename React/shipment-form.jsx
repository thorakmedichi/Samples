var ShipmentForm = React.createClass({
	getInitialState: function() {
		return {
			subject: '',
			response: {
				type: '',
				message: ''
			}
		};
	},

	componentDidUpdate: function(){
		$('.message-group').popover();
	},

	handleSubmit: function(event) {
		event.preventDefault();

		var url = '/messages/shipment/store';

		// Create a formdata object and add the files
		var data = new FormData();
		data.append("to", this.props.customerId);
		data.append("tracking_num", this.refs.newShipmentForm.tracking_num.value);
		data.append("message", this.refs.newShipmentForm.message.value);

		this.props.handleSubmit(url, data);
	},

	handleSubjectChange: function(event){
		this.setState({
			subject: event.target.value
		});
	},

	render: function() {
		return (
			<form className="newShipmentForm" onSubmit={this.handleSubmit} ref="newShipmentForm">
				<div className="form-group error">
					<div className="form-group">
						<label>Tracking Number</label>
						<input name="tracking_num" className="form-control" type="text" onChange={this.props.handleSubjectChange} required />
					</div>

					<div className="form-group message-group"
						data-toggle="popover" 
						data-trigger="manual"
						data-placement="right" 
						data-original-title={this.state.response.type} 
						data-content={this.state.response.message}>

						<label>Notes</label>
						<BuildTextarea name="message" 
							placeholder="Message" 
							messageText={this.props.messageText} 
							onMessageChange={this.props.onMessageChange} 
							disabled={this.state.disabled} 
							isRequired={true} />
					</div>
				</div>
				<button type="submit" className="btn btn-md btn-warning btn-labeled fa fa-floppy-o pull-right" disabled={this.state.disabled}>Save</button>
			</form>
		)
	},

});
window.ShipmentForm = ShipmentForm;