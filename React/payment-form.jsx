var PaymentForm = React.createClass({

	getInitialState: function() {
		return {
			disabled: false,
			amount: '0.00',
			subject: '{brand} payment',
			message: 'Thanks {name}!',
			response: {
				type: '', 
				message: ''
			}
		};
	},

	componentDidUpdate: function(){
		$('.amount-group').popover();
	},

	handleChange: function(event) {
		var input = event.target.value;

		$('.amount-group').popover('show');
		// Ensure it is a positive number and has two decimal places
		if (input < 1){
			this.setState({
				response: {
					type: 'error',
					message: 'The amount must be more than $1.00'
				}
			});
		}
		else if (!/^[0-9]*\.[0-9]{2}$/.test(input)){
			this.setState({
				response: {
					type: 'error',
					message: 'The amount must be a valid amount containing two decimal places'
				}
			});
		}
		else {
			$('.amount-group').popover('hide');
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

		var url = '/payments/paypal/send';

		// Create a formdata object and add the files
		var data = new FormData();
		data.append("to", [this.props.customerId]);
		data.append("amount", this.refs.newPaymentForm.amount.value);
		data.append("subject", this.refs.newPaymentForm.subject.value);
		data.append("message", this.refs.newPaymentForm.message.value);

		this.refs.newPaymentForm.subject.value = '';
		this.refs.newPaymentForm.message.value = '';
		this.refs.newPaymentForm.amount.value = '0.00';

		this.props.handleSubmit(url, data);
	},

	render: function() {

		return (
			<form className="newPaymentForm" onSubmit={this.handleSubmit} ref="newPaymentForm">
				<div className="form-group error">
					<label>Amount</label>
					<div className="input-group amount-group"
						data-toggle="popover" 
						data-trigger="manual"
						data-placement="right" 
						data-original-title={this.state.response.type} 
						data-content={this.state.response.message}>

						<span className="input-group-addon"><i className="fa fa-dollar fa-md"></i></span>
						<input name="amount" type="text"
							className="form-control" 
							onChange={this.handleChange}
							defaultValue={this.state.amount} 
							disabled={this.state.disabled}
							required />
					</div>
				</div>
				<div className="form-group">
					<label>Subject</label>
					<input name="subject" className="form-control" type="text" defaultValue={this.state.subject} disabled={this.state.disabled} required />
				</div>
				<div className="form-group">
					<textarea name="message" rows="5" cols="30" className="form-control" defaultValue={this.state.message} disabled={this.state.disabled}></textarea>
				</div>
				<button className="btn btn-md btn-mint btn-labeled fa fa-money pull-right" type="submit">Pay</button>
			</form>
		)
	},

});
window.PaymentForm = PaymentForm;