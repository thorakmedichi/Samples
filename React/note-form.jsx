var NoteForm = React.createClass({
	getInitialState: function() {
		return {
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

		var url = '/messages/note/store';

		// Create a formdata object and add the files
		var data = new FormData();
		data.append("to", this.props.customerId);
		data.append("message", this.refs.newNoteForm.message.value);

		this.props.handleSubmit(url, data);
	},

	render: function() {
		return (
			<form className="newNoteForm" onSubmit={this.handleSubmit} ref="newNoteForm">
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
					</div>
				</div>
				<button type="submit" className="btn btn-md btn-warning btn-labeled fa fa-floppy-o pull-right" disabled={this.state.disabled}>Save</button>
			</form>
		)
	},

});
window.NoteForm = NoteForm;