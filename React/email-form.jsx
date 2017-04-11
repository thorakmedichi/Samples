var EmailForm = React.createClass({
	getInitialState: function(){
		return {
			files: null
		};
	},

	componentDidMount: function(){
		// Make the file upload button look fancy
		$(":file").filestyle({
			//iconName: "glyphicon glyphicon-upload"
			input: false,
			buttonText: "Upload",
			buttonName: "btn-default"
			
		});
	},

	handleFile: function(event){
		this.setState({
			files: event.target.files
		});
	},

	handleSubmit: function(event) {
		event.preventDefault();

		var url = '/messages/email/send';

		// Create a formdata object and add the files
		var data = new FormData();
		data.append("to", [this.props.customerId]);
		data.append("subject", this.refs.newEmailForm.subject.value);
		data.append("message", this.refs.newEmailForm.message.value);

		// Add any attached files
		if (this.state.files != null){
			$.each(this.state.files, function(key, value) {
				data.append(key, value);
			});
		}

		this.refs.newEmailForm.subject.value = '';
		this.refs.newEmailForm.message.value = '';
		this.refs.newEmailForm.upload_file.value = '';

		this.props.handleSubmit(url, data);

		this.setState({
			files: null
		});
	},

	render: function() {
		return (
			<form className="newEmailForm" onSubmit={this.handleSubmit} ref="newEmailForm" encType="multipart/form-data">
				<div className="form-group">
					<label>Subject</label>
					<input name="subject" className="form-control" type="text" placeholder="Subject" required />
				</div>
				<div className="form-group">
					<BuildTextarea name="message" 
						placeholder="Message" 
						messageText={this.props.messageText} 
						onMessageChange={this.props.onMessageChange} 
						isRequired={true} />
				</div>
				<input type="file" name="upload_file" onChange={this.handleFile} />
				<button type="submit" className="btn btn-md btn-primary btn-labeled fa fa-send pull-right">Send</button>
			</form>
		)
	},

});
window.EmailForm = EmailForm;




